<?php

namespace Slate\Connectors\Canvas;

use Emergence\Connectors\Exceptions\SyncException;
use Emergence\Connectors\IIdentityConsumer;
use Emergence\Connectors\IJob;
use Emergence\Connectors\ISynchronize;
use Emergence\Connectors\Mapping;
use Emergence\Connectors\SyncResult;
use Emergence\KeyedDiff;
use Emergence\People\IPerson;
use Emergence\People\User;
use Emergence\SAML2\Connector as SAML2Connector;
use Emergence\Util\Data as DataUtil;
use Emergence\Util\Url as UrlUtil;
use Exception;
use Psr\Log\LoggerInterface;
use RemoteSystems\Canvas as CanvasAPI;
use RuntimeException;
use Site;
use Slate\Connectors\Canvas\Repositories\Enrollments as EnrollmentsRepository;
use Slate\Connectors\Canvas\Repositories\Users as UsersRepository;
use Slate\Connectors\Canvas\Strategies\PushEnrollments;
use Slate\Courses\Section;
use Slate\Courses\SectionParticipant;
use Slate\People\Student;
use Slate\Term;

class Connector extends SAML2Connector implements ISynchronize, IIdentityConsumer
{
    public static $sectionSkipper;
    public static $sectionTitleBuilder = [__CLASS__, 'buildSectionTitle'];
    public static $sectionTitleTermPrefix = true;

    public static $title = 'Canvas';
    public static $connectorId = 'canvas';

    public static function handleLaunchRequest()
    {
        $GLOBALS['Session']->requireAuthentication();

        if (!CanvasAPI::$canvasHost) {
            throw new \Exception('Canvas host is not configured');
        }

        $url = 'https://'.CanvasAPI::$canvasHost;

        if (!empty($_GET['course'])) {
            $url .= '/courses/'.urlencode($_GET['course']);
        }

        Site::redirect($url);
    }

    /**
     * IdentityConsumer interface methods.
     */
    public static function handleLoginRequest(IPerson $Person)
    {
        static::_fireEvent('beforeLogin', [
            'Person' => $Person,
        ]);

        return parent::handleLoginRequest($Person, __CLASS__);
    }

    public static function userIsPermitted(IPerson $Person)
    {
        if (!$Person || !\RemoteSystems\Canvas::$canvasHost) {
            return false;
        }

        if (!$Person->AccountLevel || 'Disabled' == $Person->AccountLevel || 'Contact' == $Person->AccountLevel) {
            return false;
        }

        if (!is_a($Person, User::class) && !is_a($Person, Student::class)) {
            return false;
        }

        if (!$Person->Email || !$Person->Password) {
            return false;
        }

        if (is_callable(static::$userIsPermitted)) {
            return call_user_func(static::$userIsPermitted, $Person);
        }

        return true;
    }

    public static function userShouldAutoProvision(IPerson $Person)
    {
        if (is_callable(static::$userShouldAutoProvision)) {
            return call_user_func(static::$userShouldAutoProvision, $Person);
        }

        return false;
    }

    public static function beforeAuthenticate(IPerson $Person)
    {
        static::_fireEvent('beforeAuthenticate', [
            'Person' => $Person,
        ]);

        try {
            $logger = static::getLogger();

            $userSyncResult = static::pushUser($Person, $logger, false);
            if (SyncResult::STATUS_SKIPPED == $userSyncResult->getStatus() || SyncResult::STATUS_DELETED == $userSyncResult->getStatus()) {
                return false;
            }

            $enrollmentSyncResults = static::pushEnrollments($Person, $logger, false);
        } catch (SyncException $exception) {
            // allow login if account exists
            try {
                return static::_getCanvasUserID($Person->ID);
            } catch (\Exception $e) {
                return false;
            }
        }

        if (is_callable(static::$beforeAuthenticate)) {
            if (false === call_user_func(static::$beforeAuthenticate, $Person)) {
                return false;
            }
        }

        return true;
    }

    public static function getSAMLNameId(IPerson $Person)
    {
        if ($Person->PrimaryEmail) {
            return [
                'Format' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
                'Value' => $Person->PrimaryEmail->toString(),
            ];
        }

        return parent::getSAMLNameId($Person);
    }

    public static function getLaunchUrl(Mapping $Mapping = null)
    {
        if ($Mapping && 'course[id]' == $Mapping->ExternalKey) {
            return static::getBaseUrl().'/launch?course='.$Mapping->ExternalIdentifier;
        }

        return parent::getLaunchUrl($Mapping);
    }

    public static function buildSectionTitle(Section $Section)
    {
        if (static::$sectionTitleTermPrefix) {
            $masterTerm = $Section->Term->getMaster();
            $year1 = substr($masterTerm->StartDate, 2, 2);
            $year2 = substr($masterTerm->EndDate, 2, 2);

            $title = " {$year1}";

            if ($year1 != $year2) {
                $title .= "–{$year2}";
            }

            $title .= ': ';
        } else {
            $title = '';
        }

        $title .= $Section->getTitle();

        return $title;
    }

    public static function synchronize(IJob $Job, $pretend = true)
    {
        if ('Pending' != $Job->Status && 'Completed' != $Job->Status) {
            return static::throwError('Cannot execute job, status is not Pending or Complete');
        }

        if (!CanvasAPI::$apiToken) {
            return static::throwError('Cannot execute job, Canvas apiToken not configured');
        }

        // update job status
        $Job->Status = 'Pending';

        if (!$pretend) {
            $Job->save();
        }

        // init results struct
        $results = [];

        // uncap execution time
        set_time_limit(0);

        // execute requested tasks
        if (!empty($Job->Config['pushUsers'])) {
            $results['push-users'] = static::pushUsers(
                $Job,
                $pretend
            );
        }

        if (!empty($Job->Config['pushSections'])) {
            $results['push-sections'] = static::pushSections(
                $Job,
                $pretend
            );
        }

        // save job results
        $Job->Status = 'Completed';
        $Job->Results = $results;

        if (!$pretend) {
            $Job->save();
        }

        return true;
    }

    // task handlers

    public static function pushUsers(IJob $Job, $pretend = true)
    {
        $conditions = [
            'AccountLevel IS NOT NULL AND ( (AccountLevel NOT IN ("Disabled", "Contact", "User", "Staff")) OR (Class = "Slate\\\\People\\\\Student" AND AccountLevel != "Disabled") )', // no disabled accounts, non-users, guests, and non-teaching staff
            'GraduationYear IS NULL OR GraduationYear >= '.Term::getClosestGraduationYear(), // no alumni
            'Username IS NOT NULL', // username must be assigned
        ];

        $results = [
            'analyzed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach (User::getAllByWhere($conditions) as $User) {
            $Job->debug(
                'Analyzing Slate user {slateUsername} ({slateUserClass}/{userGraduationYear})',
                [
                    'slateUsername' => $User->Username,
                    'slateUserClass' => $User->Class,
                    'userGraduationYear' => $User->GraduationYear,
                ]
            );
            ++$results['analyzed'];

            try {
                $syncResult = static::pushUser($User, $Job, $pretend);

                if (SyncResult::STATUS_CREATED === $syncResult->getStatus()) {
                    ++$results['created'];
                } elseif (SyncResult::STATUS_UPDATED === $syncResult->getStatus()) {
                    ++$results['updated'];
                } elseif (SyncResult::STATUS_SKIPPED === $syncResult->getStatus()) {
                    continue;
                }
            } catch (SyncException $e) {
                $Job->logException($e);
                ++$results['failed'];
            }

            // try {
            //     $enrollmentResult = static::pushEnrollments($User, $Job, $pretend);
            // } catch (SyncException $e) {
            //     $Job->logException($e);
            // }
        }

        return $results;
    }

    /*
    * Push Slate User data to Canvas API.
    * @param $User User object
    * @param $pretend boolean
    * @return SyncResult object
    */

    public static function pushUser(IPerson $User, LoggerInterface $logger = null, $pretend = true)
    {
        $logger = static::getLogger($logger);

        // get mapping
        $mappingData = [
            'ContextClass' => $User->getRootClass(),
            'ContextID' => $User->ID,
            'Connector' => static::getConnectorId(),
            'ExternalKey' => 'user[id]',
        ];

        // if account exists, sync
        if ($Mapping = Mapping::getByWhere($mappingData)) {
            // update user if mapping exists
            $logger->debug(
                'Found mapping to Canvas user {canvasUserId}, checking for updates...',
                [
                    'canvasUserMapping' => $Mapping,
                    'canvasUserId' => $Mapping->ExternalIdentifier,
                ]
            );

            $canvasUser = CanvasAPI::getUser($Mapping->ExternalIdentifier);
            //$Job->log('<blockquote>Canvas user response: ' . var_export($canvasUser, true) . "</blockquote>\n");

            // detect needed changes
            $changes = false;
            $canvasUserChanges = new KeyedDiff();
            $canvasLoginChanges = new KeyedDiff();

            if ($canvasUser['name'] != $User->PreferredFullName) {
                $canvasUserChanges->addChange('user[name]', $User->PreferredFullName, $canvasUser['name']);
            }

            $shortName = $User->PreferredName ?: $User->FirstName;
            if ($canvasUser['short_name'] != $shortName) {
                $canvasUserChanges->addChange('user[short_name]', $shortName, $canvasUser['short_name']);
            }

            if ($canvasUser['primary_email'] != $User->Email) {
                $canvasUserChanges->addChange('user[email]', $User->Email, $canvasUser['primary_email']);
            }

            if ($User->PrimaryPhoto && empty($canvasUser['avatar_url'])) {
                $canvasUserChanges->addChange(
                    'user[avatar][url]',
                    UrlUtil::buildAbsolute($User->PrimaryPhoto->getThumbnailRequest(300, 300)),
                    $canvasUser['avatar_url']
                );
            }

            if ($canvasUser['sis_user_id'] != $User->Username) {
                $canvasLoginChanges->addChange('login[sis_user_id]', $User->Username, $canvasUser['sis_user_id']);
            }

            if ($canvasUser['login_id'] != $User->Email) {
                $canvasLoginChanges->addChange('login[unique_id]', $User->Email, $canvasUser['login_id']);
            }

            // sync user
            if ($canvasUserChanges->hasChanges()) {
                $changes['user'] = true;

                if (!$pretend) {
                    $canvasResponse = CanvasAPI::updateUser($Mapping->ExternalIdentifier, $canvasUserChanges->getNewValues());
                    $logger->debug(
                        'Updating canvas for user {slateUsername}',
                        [
                            'slateUsername' => $User->Username,
                            'changes' => $canvasUserChanges,
                            'canvasResponse' => $canvasResponse,
                        ]
                    );
                    //$Job->log('<blockquote>Canvas update user response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                }

                $logger->notice(
                    'Updated user {slateUsername}',
                    [
                        'slateUsername' => $User->Username,
                        'changes' => $canvasUserChanges,
                    ]
                );
            } else {
                $logger->debug(
                    'Canvas user matches Slate user {slateUsername}',
                    [
                        'slateUsername' => $User->Username,
                    ]
                );
            }

            // sync login
            if ($canvasLoginChanges->hasChanges()) {
                $changes = true;

                // get existing login ID
                $logins = CanvasAPI::getLoginsByUser($Mapping->ExternalIdentifier);
                //$Job->log('<blockquote>Canvas logins response: ' . var_export($logins, true) . "</blockquote>\n");

                if (empty($logins)) {
                    throw new SyncException(
                        'Unexpected: No existing logins found for slate user {slateUsername} with canvas ID: {canvasUserId}',
                        [
                            'slateUsername' => $User->Username,
                            'canvasUserId' => $Mapping->ExternalIdentifier,
                            'canvasResponse' => $logins,
                        ]
                    );
                }

                $logger->notice(
                    'Updating login for user {slateUsername}',
                    [
                        'slateUsername' => $User->Username,
                        'changes' => $canvasLoginChanges,
                    ]
                );

                if (!$pretend) {
                    $canvasResponse = CanvasAPI::updateLogin($logins[0]['id'], $canvasLoginChanges->getNewValues());
                    $logger->debug(
                        'Updated canvas login for user {slateUsername}',
                        [
                            'slateUsername' => $User->Username,
                            'changes' => $canvasLoginChanges,
                            'canvasResponse' => $canvasResponse,
                        ]
                    );
                    //$Job->log('<blockquote>Canvas update login response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                }
            } else {
                $logger->debug(
                    'Canvas login for {slateUsername} matches Slate login',
                    [
                        'slateUsername' => $User->Username,
                    ]
                );
            }

            return new SyncResult(
                $changes ? SyncResult::STATUS_UPDATED : SyncResult::STATUS_VERIFIED,
                'Canvas account for {slateUsername} found and verified up-to-date.',
                [
                    'slateUsername' => $User->Username,
                ]
            );
        } else { // try to create user if no mapping found
            // skip accounts with no email
            if (!$User->Email) {
                return new SyncResult(
                    SyncResult::STATUS_SKIPPED,
                    'No email, skipping {slateUsername}',
                    [
                        'slateUsername' => $User->Username,
                    ]
                );
            }

            if ($pretend) {
                $logger->notice(
                    'Created canvas user for {slateUsername}',
                    [
                        'slateUsername' => $User->Username,
                    ]
                );

                return new SyncResult(
                    SyncResult::STATUS_CREATED,
                    'Created canvas user for {slateUsername}, saved mapping to new canvas user (pretend-mode)',
                    [
                        'slateUsername' => $User->Username,
                    ]
                );
            }

            $canvasResponse = CanvasAPI::createUser([
                'user[name]' => $User->PreferredFullName,
                'user[short_name]' => $User->PreferredName ?: $User->FirstName,
                'pseudonym[unique_id]' => $User->Email,
                'pseudonym[sis_user_id]' => $User->Username,
                'communication_channel[type]' => 'email',
                'communication_channel[address]' => $User->Email,
                'enable_sis_reactivation' => true,
            ]);

            $logger->notice(
                'Created canvas user for {slateUsername}',
                [
                    'slateUsername' => $User->Username,
                    'canvasResponse' => $canvasResponse,
                ]
            );

            // save external mapping if request is successful
            if (!empty($canvasResponse['id'])) {
                $mappingData['ExternalIdentifier'] = $canvasResponse['id'];
                Mapping::create($mappingData, true);

                return new SyncResult(
                    SyncResult::STATUS_CREATED,
                    'Created canvas user for {slateUsername}, saved mapping to new canvas user #{canvasUserId}',
                    [
                        'slateUsername' => $User->Username,
                        'canvasUserId' => $canvasResponse['id'],
                    ]
                );
            } else {
                throw new SyncException(
                    'Failed to create canvas user for {slateUsername}',
                    [
                        'slateUsername' => $User->Username,
                        'CanvasResponse' => $canvasResponse,
                    ]
                );
            }
        }
    }

    /*
    * Push Slate User course enrollments to Canvas API.
    * @param $User User object
    * @param $pretend boolean
    */

    public static function pushEnrollments(IPerson $User, LoggerInterface $logger, $pretend = true)
    {
        $results = [
            'created' => 0,
            'removed' => 0,
            'verified' => 0,
            'skipped' => 0,
            'updated' => 0,
        ];

        $userEnrollments = SectionParticipant::getAllByWhere([
            'PersonID' => $User->ID,
        ]);

        //sync student enrollments
        foreach ($userEnrollments as $userEnrollment) {
            // skip sections/terms that are not live
            if ('Live' != $userEnrollment->Section->Status || 'Live' != $userEnrollment->Section->Term->Status) {
                continue;
            }

            if (
                !$SectionMapping = Mapping::getByWhere([
                    'ContextClass' => $userEnrollment->Section->getRootClass(),
                    'ContextID' => $userEnrollment->Section->ID,
                    'Connector' => static::getConnectorId(),
                    'ExternalKey' => 'course_section[id]',
                ])
            ) {
                continue;
            }

            // TODO: handle changes to enrollment type.
            $userEnrollmentType = null;
            switch ($userEnrollment->Role) {
                case 'Student':
                    $userEnrollmentType = 'student';

                    break;
                case 'Assistant':
                    $userEnrollmentType = 'ta';

                    break;
                case 'Teacher':
                    $userEnrollmentType = 'teacher';

                    break;
                case 'Observer':
                    $userEnrollmentType = 'observer';

                    break;
            }

            if (!$userEnrollmentType) {
                continue;
            }

            try {
                $syncResult = static::pushSectionEnrollment(
                    $User,
                    $SectionMapping,
                    $userEnrollmentType,
                    $logger,
                    $pretend
                );

                if (SyncResult::STATUS_CREATED === $syncResult->getStatus()) {
                    ++$results['created'];
                } elseif (SyncResult::STATUS_VERIFIED === $syncResult->getStatus()) {
                    ++$results['verified'];
                } elseif (SyncResult::STATUS_UPDATED === $syncResult->getStatus()) {
                    ++$results['updated'];
                } elseif (SyncResult::STATUS_SKIPPED === $syncResult->getStatus()) {
                    ++$results['skipped'];
                } elseif (SyncResult::STATUS_DELETED === $syncResult->getStatus()) {
                    ++$results['removed'];
                }
            } catch (SyncException $e) {
                $logger->error(
                    'Unable to push {slateUsername} section enrollment for section {sectionCode}',
                    [
                        'slateUser' => $User->Username,
                        'sectionCode' => $SectionMapping->Context->Code,
                        'exception' => $e,
                    ]
                );
            }
        }

        // sync ward enrollments
        foreach ($User->Wards as $Ward) {
            // push observer enrollments for ward sections
            $StudentMapping = Mapping::getByWhere([
                'ContextClass' => $Ward->getRootClass(),
                'ContextID' => $Ward->ID,
                'Connector' => static::getConnectorId(),
                'ExternalKey' => 'user[id]',
            ]);

            if (!$StudentMapping) {
                continue;
            }

            $studentCanvasId = static::_getCanvasUserID($Ward->ID);
            // $studentCanvasEnrollments = CanvasAPI::getEnrollmentsByUser($studentCanvasId);

            $WardEnrollments = SectionParticipant::getAllByWhere([
                'PersonID' => $Ward->ID,
                'CourseSectionID' => [
                    'values' => array_map(function ($s) {
                        return $s->ID;
                    }, $Ward->CurrentCourseSections),
                ],
            ]);

            foreach ($WardEnrollments as $WardEnrollment) {
                $Section = $WardEnrollment->Section;
                $SectionMapping = Mapping::getByWhere([
                    'ContextClass' => $Section->getRootClass(),
                    'ContextID' => $Section->ID,
                    'Connector' => static::getConnectorId(),
                    'ExternalKey' => 'course_section[id]',
                ]);

                if (!$SectionMapping) {
                    ++$results['skipped'];

                    continue;
                }

                try {
                    $canvasEnrollment = static::pushSectionEnrollment(
                        $User,
                        $SectionMapping,
                        'observer',
                        $logger,
                        $pretend,
                        $studentCanvasId
                    );

                    if (SyncResult::STATUS_CREATED === $canvasEnrollment->getStatus()) {
                        ++$results['created'];
                    } elseif (SyncResult::STATUS_VERIFIED === $canvasEnrollment->getStatus()) {
                        ++$results['verified'];
                    } elseif (SyncResult::STATUS_UPDATED === $canvasEnrollment->getStatus()) {
                        ++$results['updated'];
                    } elseif (SyncResult::STATUS_SKIPPED === $canvasEnrollment->getStatus()) {
                        ++$results['skipped'];
                    } elseif (SyncResult::STATUS_DELETED === $canvasEnrollment->getStatus()) {
                        ++$results['removed'];
                    }
                } catch (SyncException $e) {
                    $logger->error(
                        'Unable to push {slateUsername} section observer enrollment for section {sectionCode}',
                        [
                            'slateUser' => $User->Username,
                            'sectionCode' => $SectionMapping->Context->Code,
                            'exception' => $e,
                        ]
                    );
                }
            }
        }

        return $results;
    }

    /*
    * Push Slate User section enrollment to Canvas API.
    * @param $User User object
    * @param $SectionMapping SectionMapping object
    * @param $enrollmentType string - currently allows: student, teacher, observer
    * @param $loggger LoggerInterface object
    * @param $pretend boolean
    * @return SyncResult object?
    */

    public static function pushSectionEnrollment(IPerson $User, Mapping $SectionMapping, $enrollmentType, LoggerInterface $logger = null, $pretend = true, $observeeId = null)
    {
        $logger = static::getLogger($logger);

        // index canvas enrollments by course_section_id
        $canvasEnrollments = static::getCanvasEnrollments($User);
        $canvasEnrollmentFound = (bool) array_key_exists($SectionMapping->ExternalIdentifier, $canvasEnrollments);

        $enrollmentData = [];
        if (!empty($observeeId)) {
            $enrollmentData['associated_user_id'] = $observeeId;
        }

        if (
            SectionParticipant::getByWhere([
                'CourseSectionID' => $SectionMapping->ContextID,
                'PersonID' => $User->ID,
            ]) ||
            (
                'observer' == $enrollmentType
                && $observeeId
            )
        ) {
            // first, delete any existing enrollment that is the wrong type
            if (
                $canvasEnrollmentFound
                && ucfirst($enrollmentType).'Enrollment' != $canvasEnrollments[$SectionMapping->ExternalIdentifier]['type']
            ) {
                if ($pretend) {
                    $deletedCanvasEnrollment = true;
                } else {
                    $deletedCanvasEnrollment = static::removeSectionEnrollment(
                        $User,
                        $SectionMapping,
                        $logger,
                        strtolower(str_replace('Enrollment', '', $canvasEnrollments[$SectionMapping->ExternalIdentifier]['type'])),
                        $canvasEnrollments[$SectionMapping->ExternalIdentifier]['id']
                    );
                }
            }

            // second, create a new enrollment if one didn't exist or we just deleted one of the wrong type
            if (!$canvasEnrollmentFound || $deletedCanvasEnrollment) {
                if ($pretend) {
                    $createdCanvasEnrollment = new SyncResult(
                        SyncResult::STATUS_CREATED,
                        'Enrolled {enrollmentType} {slateUsername} in {slateSectionCode} (pretend-mode)',
                        [
                            'slateUsername' => $User->Username,
                            'enrollmentType' => $enrollmentType,
                            'slateSectionCode' => $SectionMapping->Context->Code,
                        ]
                    );
                } else {
                    $createdCanvasEnrollment = static::createSectionEnrollment(
                        $User,
                        $SectionMapping,
                        $logger,
                        $enrollmentType,
                        $enrollmentData
                    );
                }
            }

            // return result based on recorded events
            if (!$createdCanvasEnrollment) {
                return new SyncResult(
                    SyncResult::STATUS_VERIFIED,
                    'Verified enrollment in {sectionCode} for {enrollmentType} {slateUsername} {mode}',
                    [
                        'sectionCode' => $SectionMapping->Context->Code,
                        'enrollmentType' => $enrollmentType,
                        'slateUsername' => $User->Username,
                        'mode' => $pretend ? '(pretend-mode)' : '',
                    ]
                );
            } elseif ($deletedCanvasEnrollment) {
                return new SyncResult(
                    SyncResult::STATUS_UPDATED,
                    'Updated enrollment type in {sectionCode} for {slateUsername} from {originalEnrollmentType} -> {enrollmentType} {mode}',
                    [
                        'sectionCode' => $SectionMapping->Context->Code,
                        'slateUsername' => $User->Username,
                        'originalEnrollmentType' => $canvasEnrollments[$SectionMapping->ExternalIdentifer]['type'],
                        'enrollmentType' => $enrollmentType,
                        'mode' => $pretend ? '(pretend-mode)' : '',
                    ]
                );
            }

            return $createdCanvasEnrollment;
        } elseif ($canvasEnrollmentFound) {
            if ($pretend) {
                return new SyncResult(
                    SyncResult::STATUS_DELETED,
                    'Deleted {enrollmentType} enrollment for {slateUsername} in {sectionCode}',
                    [
                        'sectionCode' => $SectionMapping->Context->Code,
                        'slateUsername' => $User->Username,
                        'enrollmentType' => $enrollmentType,
                    ]
                );
            }
            // remove section enrollment in canvas, that doesn't exist in slate
            return static::removeSectionEnrollment(
                $User,
                $SectionMapping,
                $logger,
                strtolower(str_replace('Enrollment', '', $canvasEnrollments[$SectionMapping->ExternalIdentifier]['type'])),
                $canvasEnrollments[$SectionMapping->ExternalIdentifier]['id']
            );
        } else {
            // skip sections that users are not enrolled in
            return new SyncResult(
                SyncResult::STATUS_SKIPPED,
                'Skipped section {sectionCode} that {slateUsername} is not enrolled in for either Slate or Canvas',
                [
                    'sectionCode' => $SectionMapping->Context->Code,
                    'slateUsername' => $User->Username,
                ]
            );
        }
    }

    // replace log functions
    // use job as logger and update log method
    // log all api calls
    // update references to createSectionEnrollment & removeSectionEnrollment
    public static function pushSections(IJob $Job, $pretend = true)
    {
        if (empty($Job->Config['masterTerm'])) {
            $Job->logException(new Exception('masterTerm required to import sections'));

            return false;
        }

        if (!$MasterTerm = Term::getByHandle($Job->Config['masterTerm'])) {
            $Job->logException(new Exception('masterTerm not found'));

            return false;
        }

        $now = strtotime('now');
        $sectionConditions = [
            'TermID' => [
                'values' => $MasterTerm->getContainedTermIDs(),
                'operator' => 'IN',
            ],
        ];

        $results = [
            'courses' => [],
            'sections' => [],
            'enrollments' => [],
        ];

        foreach (Section::getAllByWhere($sectionConditions) as $Section) {
            if (
                is_callable(static::$sectionSkipper)
                && ($skipCode = call_user_func(static::$sectionSkipper, $Section))
            ) {
                ++$results['sections']['skipped'][$skipCode];
                $Job->notice(
                    'Skipping section {sectionCode} via configured skipper: {skipCode}',
                    [
                        'sectionCode' => $Section->Code,
                        'skipCode' => $skipCode,
                    ]
                );

                continue;
            }

            $canvasSection = null;

            // build section title
            $sectionTitle = call_user_func(static::$sectionTitleBuilder, $Section);

            $Job->debug(
                'Analyzing Slate section {sectionTitle} ({sectionCode})',
                [
                    'sectionTitle' => $sectionTitle,
                    'sectionCode' => $Section->Code,
                ]
            );

            ++$results['sections']['analyzed'];

            if (!count($Section->Students) && empty($Job->Config['includeEmptySections'])) {
                ++$results['sections']['skipped']['no-students'];
                $Job->notice(
                    'Skipping section {sectionCode} with no students.',
                    [
                        'sectionCode' => $Section->Code,
                    ]
                );

                continue;
            }

            // sync course first

            // get mapping
            $courseMappingData = [
                'ContextClass' => $Section->getRootClass(),
                'ContextID' => $Section->ID,
                'Connector' => static::getConnectorId(),
                'ExternalKey' => 'course[id]',
            ];

            if (!$CourseMapping = Mapping::getByWhere($courseMappingData)) {
                if ($pretend) {
                    ++$results['courses']['created'];
                    $Job->notice(
                        'Created canvas course for {sectionTitle} ({sectionCode})',
                        [
                            'sectionTitle' => $sectionTitle,
                            'sectionCode' => $Section->Code,
                        ]
                    );
                } else {
                    $canvasResponse = CanvasAPI::createCourse([
                        'account_id' => CanvasAPI::$accountID,
                        'course[name]' => $sectionTitle,
                        'course[course_code]' => $Section->Code,
                        'course[start_at]' => $Section->Term->StartDate,
                        'course[end_at]' => $Section->Term->EndDate,
                        'course[sis_course_id]' => $Section->Code,
                        'enable_sis_reactivation' => true,
                    ]);

                    $Job->debug(
                        'Attempting to create canvas course for {sectionTitle} ({sectionCode}).',
                        [
                            'sectionTitle' => $sectionTitle,
                            'sectionCode' => $Section->Code,
                            'canvasResponse' => $canvasResponse,
                        ]
                    );

                    if (empty($canvasResponse['id'])) {
                        ++$results['courses']['failed'];
                        $Job->error(
                            'Failed to create canvas course for {sectionTitle} ({sectionCode})',
                            [
                                'sectionTitle' => $sectionTitle,
                                'sectionCode' => $Section->Code,
                            ]
                        );

                        continue;
                    } else {
                        $courseMappingData['ExternalIdentifier'] = $canvasResponse['id'];
                        $CourseMapping = Mapping::create($courseMappingData, true);
                        ++$results['courses']['created'];

                        $Job->notice(
                            'Created canvas section for course {sectionTitle} ({sectionCode}), saved mapping to new canvas course #{canvasCourseExternalId}',
                            [
                                'sectionTitle' => $sectionTitle,
                                'sectionCode' => $Section->Code,
                                'canvasCourseExternalId' => $canvasResponse['id'],
                                'canvasResponse' => $canvasResponse,
                            ]
                        );
                    }
                }
            } else {
                ++$results['courses']['existing'];

                // update user if mapping exists
                $Job->debug(
                    'Found mapping to Canvas course {canvasCourseExternalId}, checking for updates...',
                    [
                        'canvasCourseExternalId' => $CourseMapping->ExternalIdentifier,
                    ]
                );

                try {
                    $canvasCourse = CanvasAPI::getCourse($CourseMapping->ExternalIdentifier);
                } catch (RuntimeException $e) {
                    ++$results['courses']['failed'];
                    $Job->error(
                        'Failed to fetch Canvas course {canvasId}: {canvasError} (status: {canvasStatus})',
                        [
                            'canvasId' => $CourseMapping->ExternalIdentifier,
                            'canvasError' => $e->getMessage(),
                            'canvasStatus' => $e->getCode(),
                        ]
                    );

                    continue;
                }

                $changes = new KeyedDiff();

                if ($canvasCourse['name'] != $sectionTitle) {
                    $changes->addChange('course[name]', $sectionTitle, $canvasCourse['name']);
                }

                if ($canvasCourse['course_code'] != $Section->Code) {
                    $changes->addChange('course[course_code]', $Section->Code, $canvasCourse['course_code']);
                }

                if ($canvasCourse['sis_course_id'] != $Section->Code) {
                    $changes->addChange('course[sis_course_id]', $Section->Code, $canvasCourse['sis_course_id']);
                }

                if (0 !== strpos($canvasCourse['start_at'], $Section->Term->StartDate)) {
                    $changes->addChange('course[start_at]', $Section->Term->StartDate, $canvasCourse['start_at']);
                }

                if (0 !== strpos($canvasCourse['end_at'], $Section->Term->EndDate)) {
                    $changes->addChange('course[end_at]', $Section->Term->EndDate, $canvasCourse['end_at']);
                }

                if (!empty($Job->Config['canvasTerm']) && $canvasCourse['enrollment_term_id'] != $Job->Config['canvasTerm']) {
                    $changes->addChange('course[term_id]', $Job->Config['canvasTerm'], $canvasCourse['enrollment_term_id']);
                }

                if (!$changes->hasChanges()) {
                    $Job->debug(
                        'Canvas course data for {canvasCourseCode} matches Slate course.',
                        [
                            'canvasCourseCode' => $Section->Code,
                        ]
                    );
                } else {
                    ++$results['courses']['updated'];

                    $Job->notice('Updating course {canvasCourseCode}', [
                        'action' => 'update',
                        'changes' => $changes,
                        'canvasCourseCode' => $Section->Code,
                    ]);

                    if (!$pretend) {
                        $canvasResponse = CanvasAPI::updateCourse($CourseMapping->ExternalIdentifier, $changes->getNewValues());
                        $Job->debug(
                            'Canvas course {canvasCourseCode} updated',
                            [
                                'canvasCourseCode' => $Section->Code,
                                'canvasResponse' => $canvasResponse,
                            ]
                        );
                    }
                }
            }

            // sync section
            $sectionMappingData = [
                'ContextClass' => $Section->getRootClass(),
                'ContextID' => $Section->ID,
                'Connector' => static::getConnectorId(),
                'ExternalKey' => 'course_section[id]',
            ];

            if (!$SectionMapping = Mapping::getByWhere($sectionMappingData)) {
                if ($pretend) {
                    ++$results['sections']['created'];
                    $Job->notice(
                        'Created canvas section for {sectionTitle}',
                        [
                            'sectionTitle' => $sectionTitle,
                        ]
                    );
                } else {
                    $canvasResponse = CanvasAPI::createSection($CourseMapping->ExternalIdentifier, [
                        'course_section[name]' => $sectionTitle,
                        'course_section[start_at]' => $Section->Term->StartDate,
                        'course_section[end_at]' => $Section->Term->EndDate,
                        'course_section[sis_section_id]' => $Section->Code,
                        'enable_sis_reactivation' => true,
                    ]);

                    $Job->debug(
                        'Attempting to create canvas section for {sectionTitle} ({sectionCode}).',
                        [
                            'sectionTitle' => $sectionTitle,
                            'sectionCode' => $Section->Code,
                            'canvasResponse' => $canvasResponse,
                        ]
                    );

                    if (empty($canvasResponse['id'])) {
                        ++$results['sections']['failed'];
                        $Job->error(
                            'Failed to create canvas section',
                            [
                                'canvasResponse' => $canvasResponse,
                            ]
                        );

                        continue;
                    } else {
                        $sectionMappingData['ExternalIdentifier'] = $canvasResponse['id'];
                        $SectionMapping = Mapping::create($sectionMappingData, true);

                        ++$results['sections']['created'];
                        $Job->notice(
                            'Created canvas section for {sectionTitle}, saved mapping to new canvas section #{canvasSectionExternalId}',
                            [
                                'sectionTitle' => $sectionTitle,
                                'canvasSectionExternalId' => $canvasResponse['id'],
                                'canvasResponse' => $canvasResponse,
                            ]
                        );
                    }
                }
            } else {
                ++$results['sections']['existing'];

                // update user if mapping exists
                $Job->debug(
                    'Found mapping to Canvas section {canvasSectionExternalId}, checking for updates...',
                    [
                        'canvasSectionExternalId' => $SectionMapping->ExternalIdentifier,
                    ]
                );

                $canvasSection = CanvasAPI::getSection($SectionMapping->ExternalIdentifier);

                $changes = new KeyedDiff();

                if ($canvasSection['name'] != $sectionTitle) {
                    $changes->addChange('course_section[name]', $sectionTitle, $canvasSection['name']);
                }

                if ($canvasSection['sis_section_id'] != $Section->Code) {
                    $changes->addChange('course_section[sis_section_id]', $Section->Code, $canvasSection['sis_section_id']);
                }

                if (0 !== strpos($canvasSection['start_at'], $Section->Term->StartDate)) {
                    $changes->addChange('course_section[start_at]', $Section->Term->StartDate, $canvasSection['start_at']);
                }

                if (0 !== strpos($canvasSection['end_at'], $Section->Term->EndDate)) {
                    $changes->addChange('course_section[end_at]', $Section->Term->EndDate, $canvasSection['end_at']);
                }

                if (!$changes->hasChanges()) {
                    $Job->debug(
                        'Canvas section {sectionTitle} matches Slate section.',
                        [
                            'sectionTitle' => $sectionTitle,
                        ]
                    );
                } else {
                    if (!$pretend) {
                        $canvasResponse = CanvasAPI::updateSection($SectionMapping->ExternalIdentifier, $changes->getNewValues());
                        $Job->debug(
                            'Canvas update section response for section: {sectionTitle}',
                            [
                                'sectionTitle' => $sectionTitle,
                                'canvasResponse' => $canvasResponse,
                            ]
                        );
                    }

                    $Job->notice('Updating section {sectionTitle}', [
                        'action' => 'update',
                        'changes' => $changes,
                        'sectionTitle' => $sectionTitle,
                    ]);

                    ++$results['sections']['updated'];
                }
            }

            // sync enrollments
            if (!empty($Job->Config['syncParticiants'])) {
                // TODO: sync dynamic observers?

                $strategy = new PushEnrollments(
                    $Job,
                    [
                        'sis_section_id' => $Section->Code,
                        'inactivate_ended' => !empty($Job->Config['concludeEndedEnrollments']),
                    ]
                );

                foreach ($strategy->plan() as $command) {
                    list($message, $context) = $command->describe();
                    $Job->notice($message, $context);
                    ++$results['enrollments'][get_class($command)];

                    if (!$pretend) {
                        $request = $command->buildRequest();

                        try {
                            API::execute($request);
                        } catch (Exception $e) {
                            $Job->logException($e);
                        }
                    }
                }
            } // end syncParticiants
        } // end sections loop

        return $results;
    }

    // workflow implementations
    protected static function _getJobConfig(array $requestData)
    {
        $config = parent::_getJobConfig($requestData);

        $config['pushUsers'] = !empty($requestData['pushUsers']);

        $config['masterTerm'] = !empty($requestData['masterTerm']) ? $requestData['masterTerm'] : null;
        $config['canvasTerm'] = !empty($requestData['canvasTerm']) ? $requestData['canvasTerm'] : null;
        $config['pushSections'] = !empty($requestData['pushSections']);
        $config['syncParticiants'] = !empty($requestData['syncParticiants']);
        $config['syncObservers'] = !empty($requestData['syncObservers']);
        $config['removeTeachers'] = !empty($requestData['removeTeachers']);
        $config['includeEmptySections'] = !empty($requestData['includeEmptySections']);
        $config['concludeEndedEnrollments'] = !empty($requestData['concludeEndedEnrollments']);

        return $config;
    }

    protected static function getCanvasEnrollments(IPerson $User)
    {
        static $enrollmentsByUser = [];

        if (!isset($enrollmentsByUser[$User->ID])) {
            $enrollmentsByUser[$User->ID] = [];

            foreach (CanvasAPI::getEnrollmentsByUser(static::_getCanvasUserId($User->ID)) as $enrollment) {
                if ('deleted' === $enrollment['enrollment_state']) {
                    continue;
                }

                $enrollmentsByUser[$User->ID][$enrollment['course_section_id']] = [
                    'id' => $enrollment['id'],
                    'type' => $enrollment['type'],
                ];
            }
        }

        return $enrollmentsByUser[$User->ID];
    }

    /**
     * Create enrollment in canvas for user.
     *
     * @param $User User object - User to enroll
     * @param $CourseMapping Mapping object - Mapping to canvas course to enroll user to
     * @param $SectionMapping Mapping object - Mapping to canvas course section to enroll user to
     * @param $settings array - Config array containing enrollment options
     *
     * @return SyncResult object / SyncException object
     */
    protected static function createSectionEnrollment(IPerson $User, Mapping $SectionMapping, LoggerInterface $logger, $enrollmentType, array $enrollmentData = [])
    {
        switch ($enrollmentType) {
            case 'student':
            case 'teacher':
            case 'observer':
            case 'ta':
                break;

            default:
                throw new \Exception("Enrollment type invalid. ($enrollmentType)");
        }

        $canvasUserId = static::_getCanvasUserID($User->ID);

        try {
            $requestData = [
                'enrollment[user_id]' => $canvasUserId,
                'enrollment[type]' => ucfirst($enrollmentType).'Enrollment',
                'enrollment[enrollment_state]' => 'active',
                'enrollment[notify]' => 'false',
            ];

            foreach ($enrollmentData as $enrollmentKey => $enrollmentValue) {
                $requestData["enrollment[{$enrollmentKey}]"] = $enrollmentValue;
            }

            $canvasResponse = CanvasAPI::createEnrollmentsForSection(
                $SectionMapping->ExternalIdentifier,
                $requestData
            );

            $logger->notice(
                'Creating {enrollmentType} enrollment for {slateUsername} in {sectionCode}',
                [
                    'enrollmentType' => $enrollmentType,
                    'slateUsername' => $User->Username,
                    'sectionCode' => $SectionMapping->Context->Code,
                    'apiResponse' => $canvasResponse,
                ]
            );
        } catch (\Exception $e) {
            throw new SyncException(
                'Failed to create {enrollmentType} enrollment for {slateUsername} - {sectionCode} in Canvas',
                [
                    'enrollmentType' => $enrollmentType,
                    'slateUsername' => $User->Username,
                    'sectionCode' => $SectionMapping->Context->Code,
                    'exception' => $e,
                ]
            );
        }

        return new SyncResult(
            SyncResult::STATUS_CREATED,
            'Enrolled {enrollmentType} {slateUsername} in {slateSectionCode}',
            [
                'slateUsername' => $User->Username,
                'enrollmentType' => $enrollmentType,
                'slateSectionCode' => $SectionMapping->Context->Code,
            ]
        );
    }

    /**
     * Remove enrollment in canvas for user.
     *
     * @param $User User object - User to unenroll
     * @param $CourseMapping Mapping object - Mapping to canvas course to unenroll user to
     * @param $SectionMapping Mapping object - Mapping to canvas course section to unenroll user to
     * @param $settings array - Config array containing unenrollment options
     *
     * @return SyncResult object / SyncException object
     */
    protected static function removeSectionEnrollment(IPerson $User, Mapping $SectionMapping, LoggerInterface $logger, $enrollmentType, $enrollmentId, $enrollmentTask = 'conclude')
    {
        if (
            'student' != $enrollmentType
            && 'teacher' != $enrollmentType
            && 'observer' != $enrollmentType
            && 'ta' != $enrollmentType
        ) {
            throw new \Exception("Cannot remove enrollment type: $enrollmentType");
        }

        if (!isset($enrollmentId)) {
            throw new \Exception('Enrollment ID must be supplied.');
        }

        $canvasUserId = static::_getCanvasUserID($User->ID);
        $canvasSection = CanvasAPI::getSection($SectionMapping->ExternalIdentifier);

        try {
            $canvasResponse = CanvasAPI::deleteEnrollmentsForCourse(
                $canvasSection['course_id'],
                $enrollmentId,
                $enrollmentTask
            );

            $logger->debug(
                'Removing {enrollmentType} enrollment for {slateUsername} in {sectionCode}',
                [
                    'enrollmentType' => ucfirst($enrollmentType).'Enrollment',
                    'slateUsername' => $User->Username,
                    'sectionCode' => $SectionMapping->Context->Code,
                    'apiResponse' => $canvasResponse,
                ]
            );
        } catch (\Exception $e) {
            throw new SyncException(
                'Unable to delete {enrollmentType} enrollment for {slateUsername} in {sectionCode}',
                [
                    'enrollmentType' => ucfirst($enrollmentType).'Enrollment',
                    'slateUsername' => $User->Username,
                    'slateSectionCode' => $SectionMapping->Context->Code,
                    'exception' => $e,
                ]
            );
        }

        return new SyncResult(
            SyncResult::STATUS_DELETED,
            'Deleted {enrollmentType} enrollment for {slateUsername} in {sectionCode}',
            [
                'sectionCode' => $SectionMapping->Context->Code,
                'slateUsername' => $User->Username,
                'enrollmentType' => $enrollmentType,
            ]
        );
    }

    /**
     *  protected methods.
     */
    protected static function _getCanvasUserID($userId)
    {
        static $cache = [];

        if (!array_key_exists($userId, $cache)) {
            $UserMapping = Mapping::getByWhere([
                'ContextClass' => User::getStaticRootClass(),
                'ContextID' => $userId,
                'Connector' => static::getConnectorId(),
                'ExternalKey' => 'user[id]',
            ]);

            if (!$UserMapping) {
                throw new \Exception("Could not find canvas mapping for user #$userId");
            }

            $cache[$userId] = $UserMapping->ExternalIdentifier;
        }

        return $cache[$userId];
    }
}
