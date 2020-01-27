<?php

namespace Slate\Connectors\Canvas;

use RuntimeException;

use Psr\Log\LogLevel;
use Psr\Log\LoggerInterface;

use Site;

use RemoteSystems\Canvas as CanvasAPI;


use Emergence\Connectors\IIdentityConsumer;
use Emergence\Connectors\ISynchronize;
use Emergence\EventBus;

use Emergence\Connectors\IJob;
use Emergence\Connectors\Mapping;
use Emergence\People\IPerson;
use Emergence\People\Person;
use Emergence\People\User;
use Emergence\Util\Data as DataUtil;
use Emergence\Util\Url as UrlUtil;
use Emergence\SAML2\Connector as SAML2Connector;
use Emergence\Connectors\SyncResult;
use Emergence\Connectors\Exceptions\SyncException;

use Slate\Term;
use Slate\Courses\Section;
use Slate\Courses\SectionParticipant;
use Slate\People\Student;

class Connector extends SAML2Connector implements ISynchronize, IIdentityConsumer
{
    public static $sectionSkipper;
    public static $sectionTitleBuilder = [__CLASS__, 'buildSectionTitle'];


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
            $url .= '/courses/' . urlencode($_GET['course']);
        }

        Site::redirect($url);
    }

    /**
    * IdentityConsumer interface methods
    */
    public static function handleLoginRequest(IPerson $Person)
    {
        static::_fireEvent('beforeLogin', [
            'Person' => $Person
        ]);

        return parent::handleLoginRequest($Person, __CLASS__);
    }

    public static function userIsPermitted(IPerson $Person)
    {
        if (!$Person || !\RemoteSystems\Canvas::$canvasHost) {
            return false;
        }

        if (!$Person->AccountLevel || $Person->AccountLevel == 'Disabled' || $Person->AccountLevel == 'Contact') {
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
            'Person' => $Person
        ]);

        try {
            $logger = static::getLogger();

            $userSyncResult = static::pushUser($Person, $logger, false);
            if ($userSyncResult->getStatus() == SyncResult::STATUS_SKIPPED || $userSyncResult->getStatus() == SyncResult::STATUS_DELETED) {
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
                'Value' => $Person->PrimaryEmail->toString()
            ];
        }

        return parent::getSAMLNameId($Person);
    }

    public static function getLaunchUrl(Mapping $Mapping = null)
    {
        if ($Mapping && $Mapping->ExternalKey == 'course[id]') {
            return static::getBaseUrl() . '/launch?course=' . $Mapping->ExternalIdentifier;
        }

        return parent::getLaunchUrl($Mapping);
    }

    public static function buildSectionTitle(Section $Section)
    {
        $sectionTitle = $Section->Title;

        if ($Section->Schedule) {
            $sectionTitle .= ' (' . $Section->Schedule->Title . ')';
        }

        return $sectionTitle;
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

    public static function synchronize(IJob $Job, $pretend = true)
    {
        if ($Job->Status != 'Pending' && $Job->Status != 'Completed') {
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
            'GraduationYear IS NULL OR GraduationYear >= ' . Term::getClosestGraduationYear(), // no alumni
            'Username IS NOT NULL' // username must be assigned
        ];

        $results = [
            'analyzed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0
        ];

        foreach (User::getAllByWhere($conditions) as $User) {
            $Job->debug(
                'Analyzing Slate user {slateUsername} ({slateUserClass}/{userGraduationYear})',
                [
                    'slateUsername' => $User->Username,
                    'slateUserClass' => $User->Class,
                    'userGraduationYear' => $User->GraduationYear
                ]
            );
            $results['analyzed']++;

            try {
                $syncResult = static::pushUser($User, $Job, $pretend);

                if ($syncResult->getStatus() === SyncResult::STATUS_CREATED) {
                    $results['created']++;
                } elseif ($syncResult->getStatus() === SyncResult::STATUS_UPDATED) {
                    $results['updated']++;
                } elseif ($syncResult->getStatus() === SyncResult::STATUS_SKIPPED) {
                    continue;
                }
            } catch (SyncException $e) {
                $Job->logException($e);
                $results['failed']++;
            }
/*
            try {
                $enrollmentResult = static::pushEnrollments($User, $Job, $pretend);
            } catch (SyncException $e) {
                $Job->logException($e);
            }*/
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
            'ExternalKey' => 'user[id]'
        ];

        // if account exists, sync
        if ($Mapping = Mapping::getByWhere($mappingData)) {
            // update user if mapping exists
            $logger->debug(
                'Found mapping to Canvas user {canvasUserId}, checking for updates...',
                [
                    'canvasUserMapping' => $Mapping,
                    'canvasUserId' => $Mapping->ExternalIdentifier
                ]
            );

            $canvasUser = CanvasAPI::getUser($Mapping->ExternalIdentifier);
            //$Job->log('<blockquote>Canvas user response: ' . var_export($canvasUser, true) . "</blockquote>\n");

            // detect needed changes
            $changes = [];
            $canvasUserChanges = [];
            $canvasLoginChanges = [];

            if ($canvasUser['name'] != $User->PreferredFullName) {
                $canvasUserChanges['user[name]'] = [
                    'from' => $canvasUser['name'],
                    'to' => $User->PreferredFullName
                ];
            }

            $shortName = $User->PreferredName ?: $User->FirstName;
            if ($canvasUser['short_name'] != $shortName) {
                $canvasUserChanges['user[short_name]'] = [
                    'from' => $canvasUser['short_name'],
                    'to' => $shortName
                ];
            }

            if ($canvasUser['primary_email'] != $User->Email) {
                $canvasUserChanges['user[email]'] = [
                    'from' => $canvasUser['primary_email'],
                    'to' => $User->Email
                ];
            }

            if ($User->PrimaryPhoto && empty($canvasUser['avatar_url'])) {
                $canvasUserChanges['user[avatar][url]'] = [
                    'from' => $canvasUser['avatar_url'],
                    'to' => UrlUtil::buildAbsolute($User->PrimaryPhoto->getThumbnailRequest(300, 300))
                ];
            }

            if ($canvasUser['sis_user_id'] != $User->Username) {
                $canvasLoginChanges['login[sis_user_id]'] = [
                    'from' => $canvasUser['sis_user_id'],
                    'to' => $User->Username
                ];
            }

            if ($canvasUser['login_id'] != $User->Email) {
                $canvasLoginChanges['login[unique_id]'] = [
                    'from' => $canvasUser['login_id'],
                    'to' => $User->Email
                ];
            }

            // sync user
            if (!empty($canvasUserChanges)) {
                $changes['user'] = $canvasUserChanges;
                if (!$pretend) {
                    $canvasResponse = CanvasAPI::updateUser($Mapping->ExternalIdentifier, DataUtil::extractToFromDelta($canvasUserChanges));
                    $logger->debug(
                        'Updating canvas for user {slateUsername}',
                        [
                            'slateUsername' => $User->Username,
                            'canvasUserChanges' => $canvasUserChanges,
                            'canvasResponse' => $canvasResponse
                        ]
                    );
                    //$Job->log('<blockquote>Canvas update user response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                }

                $logger->notice(
                    'Updated user {slateUsername}',
                    [
                        'slateUsername' => $User->Username,
                        'changes' => $changes['user']
                    ]
                );
            } else {
                $logger->debug(
                    'Canvas user matches Slate user {slateUsername}',
                    [
                        'slateUsername' => $User->Username
                    ]
                );
            }

            // sync login
            if (!empty($canvasLoginChanges)) {
                $changes['login'] = $canvasLoginChanges;

                // get existing login ID
                $logins = CanvasAPI::getLoginsByUser($Mapping->ExternalIdentifier);
                //$Job->log('<blockquote>Canvas logins response: ' . var_export($logins, true) . "</blockquote>\n");

                if (empty($logins)) {
                    throw new SyncException(
                        'Unexpected: No existing logins found for slate user {slateUsername} with canvas ID: {canvasUserId}',
                        [
                            'slateUsername' => $User->Username,
                            'canvasUserId' => $Mapping->ExternalIdentifier,
                            'canvasResponse' => $logins
                        ]
                    );
                }

                $logger->notice(
                    'Updating login for user {slateUsername}',
                    [
                        'slateUsername' => $User->Username,
                        'changes' => $changes['login']
                    ]
                );

                if (!$pretend) {
                    $canvasResponse = CanvasAPI::updateLogin($logins[0]['id'], DataUtil::extractToFromDelta($canvasLoginChanges));
                    $logger->debug(
                        'Updated canvas login for user {slateUsername}',
                        [
                            'slateUsername' => $User->Username,
                            'canvasLoginChanges' => $canvasLoginChanges,
                            'canvasResponse' => $canvasResponse
                        ]
                    );
                    //$Job->log('<blockquote>Canvas update login response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                }
            } else {
                $logger->debug(
                    'Canvas login for {slateUsername} matches Slate login',
                    [
                        'slateUsername' => $User->Username
                    ]
                );
            }

            return new SyncResult(
                !empty($changes) ? SyncResult::STATUS_UPDATED : SyncResult::STATUS_VERIFIED,
                'Canvas account for {slateUsername} found and verified up-to-date.',
                [
                    'slateUsername' => $User->Username
                ]
            );
        } else { // try to create user if no mapping found
            // skip accounts with no email
            if (!$User->Email) {
                return new SyncResult(
                    SyncResult::STATUS_SKIPPED,
                    'No email, skipping {slateUsername}',
                    [
                        'slateUsername' => $User->Username
                    ]
                );
            }

            if ($pretend) {
                $logger->notice(
                    'Created canvas user for {slateUsername}',
                    [
                        'slateUsername' => $User->Username
                    ]
                );

                return new SyncResult(
                    SyncResult::STATUS_CREATED,
                    'Created canvas user for {slateUsername}, saved mapping to new canvas user (pretend-mode)',
                    [
                        'slateUsername' => $User->Username
                    ]
                );
            }

            $canvasResponse = CanvasAPI::createUser([
                'user[name]' => $User->PreferredFullName,
                'user[short_name]' => $User->PreferredName ?: $User->FirstName,
                'pseudonym[unique_id]' => $User->Email,
                'pseudonym[sis_user_id]' => $User->Username,
                'communication_channel[type]' => 'email',
                'communication_channel[address]' => $User->Email
            ]);

            $logger->notice(
                'Created canvas user for {slateUsername}',
                [
                    'slateUsername' => $User->Username,
                    'canvasResponse' => $canvasResponse
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
                        'canvasUserId' => $canvasResponse['id']
                    ]
                );
            } else {
                throw new SyncException(
                    'Failed to create canvas user for {slateUsername}',
                    [
                        'slateUsername' => $User->Username,
                        'CanvasResponse' => $canvasResponse
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
            'updated' => 0
        ];

        $userEnrollments = SectionParticipant::getAllByWhere([
            'PersonID' => $User->ID
        ]);

        //sync student enrollments
        foreach ($userEnrollments as $userEnrollment) {
            // skip sections/terms that are not live
            if ($userEnrollment->Section->Status != 'Live' || $userEnrollment->Section->Term->Status != 'Live') {
                continue;
            }

            if (
                !$SectionMapping = Mapping::getByWhere([
                    'ContextClass' => $userEnrollment->Section->getRootClass(),
                    'ContextID' => $userEnrollment->Section->ID,
                    'Connector' => static::getConnectorId(),
                    'ExternalKey' => 'course_section[id]'
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

                if ($syncResult->getStatus() === SyncResult::STATUS_CREATED) {
                    $results['created']++;
                } elseif ($syncResult->getStatus() === SyncResult::STATUS_VERIFIED) {
                    $results['verified']++;
                } elseif ($syncResult->getStatus() === SyncResult::STATUS_UPDATED) {
                    $results['updated']++;
                } elseif ($syncResult->getStatus() === SyncResult::STATUS_SKIPPED) {
                    $results['skipped']++;
                } elseif ($syncResult->getStatus() === SyncResult::STATUS_DELETED) {
                    $results['removed']++;
                }
            } catch (SyncException $e) {
                $logger->error(
                    'Unable to push {slateUsername} section enrollment for section {sectionCode}',
                    [
                        'slateUser' => $User->Username,
                        'sectionCode' => $SectionMapping->Context->Code,
                        'exception' => $e
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
                'ExternalKey' => 'user[id]'
            ]);

            if (!$StudentMapping) {
                continue;
            }

            $studentCanvasId = static::_getCanvasUserID($Ward->ID);
            $studentCanvasEnrollments = CanvasAPI::getEnrollmentsByUser($studentCanvasId);

            $WardEnrollments = SectionParticipant::getAllByWhere([
                'PersonID' => $Ward->ID,
                'CourseSectionID' => [
                    'values' => array_map(function ($s) {
                        return $s->ID;
                    }, $Ward->CurrentCourseSections)
                ]
            ]);

            foreach ($WardEnrollments as $WardEnrollment) {
                $Section = $WardEnrollment->Section;
                $SectionMapping = Mapping::getByWhere([
                    'ContextClass' => $Section->getRootClass(),
                    'ContextID' => $Section->ID,
                    'Connector' => static::getConnectorId(),
                    'ExternalKey' => 'course_section[id]'
                ]);

                if (!$SectionMapping) {
                    $results['skipped']++;
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

                    if ($canvasEnrollment->getStatus() === SyncResult::STATUS_CREATED) {
                        $results['created']++;
                    } elseif ($canvasEnrollment->getStatus() === SyncResult::STATUS_VERIFIED) {
                        $results['verified']++;
                    } elseif ($canvasEnrollment->getStatus() === SyncResult::STATUS_UPDATED) {
                        $results['updated']++;
                    } elseif ($canvasEnrollment->getStatus() === SyncResult::STATUS_SKIPPED) {
                        $results['skipped']++;
                    } elseif ($canvasEnrollment->getStatus() === SyncResult::STATUS_DELETED) {
                        $results['removed']++;
                    }
                } catch (SyncException $e) {
                    $logger->error(
                        'Unable to push {slateUsername} section observer enrollment for section {sectionCode}',
                        [
                            'slateUser' => $User->Username,
                            'sectionCode' => $SectionMapping->Context->Code,
                            'exception' => $e
                        ]
                    );
                }
            }
        }

        return $results;
    }


    protected static function getCanvasEnrollments(IPerson $User)
    {
        static $enrollmentsByUser = [];

        if (!isset($enrollmentsByUser[$User->ID])) {
            $enrollmentsByUser[$User->ID] = [];

            foreach (CanvasAPI::getEnrollmentsByUser(static::_getCanvasUserId($User->ID)) as $enrollment) {
                if ($enrollment['enrollment_state'] === 'deleted') {
                    continue;
                }

                $enrollmentsByUser[$User->ID][$enrollment['course_section_id']] = [
                    'id' => $enrollment['id'],
                    'type' => $enrollment['type']
                ];
            }
        }

        return $enrollmentsByUser[$User->ID];
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
        $canvasEnrollmentFound = !!array_key_exists($SectionMapping->ExternalIdentifier, $canvasEnrollments);

        $enrollmentData = [];
        if (!empty($observeeId)) {
            $enrollmentData['associated_user_id'] = $observeeId;
        }

        if (
            SectionParticipant::getByWhere([
                'CourseSectionID' => $SectionMapping->ContextID,
                'PersonID' => $User->ID
            ]) ||
            (
                $enrollmentType == 'observer' &&
                $observeeId
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
                            'slateSectionCode' => $SectionMapping->Context->Code
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
                        'mode' => $pretend ? '(pretend-mode)' : ''
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
                        'mode' => $pretend ? '(pretend-mode)' : ''
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
                        'enrollmentType' => $enrollmentType

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
                    'slateUsername' => $User->Username
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
                'operator' => 'IN'
            ]
        ];

        $results = [
            'analyzed' => [
                'sections' => 0,
                'enrollments' => 0
            ],

            'existing' => [
                'courses' => 0,
                'sections' => 0,
                'enrollments' => 0
            ],

            'created' => [
                'courses' => 0,
                'sections' => 0,
                'enrollments' => 0
            ],

            'updated' => [
                'courses' => 0,
                'sections' => 0,
                'enrollments' => 0
            ],

            'failed' => [
                'courses' => 0,
                'sections' => 0
            ],

            'skipped' => [
                'courses' => 0,
                'sections' => []
            ]

        ];

        foreach (Section::getAllByWhere($sectionConditions) as $Section) {
            if (
                is_callable(static::$sectionSkipper)
                && ($skipCode = call_user_func(static::$sectionSkipper, $Section))
            ) {
                $results['skipped']['sections'][$skipCode]++;
                $Job->notice(
                    'Skipping section {sectionCode} via configured skipper: {skipCode}',
                    [
                        'sectionCode' => $Section->Code,
                        'skipCode' => $skipCode
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
                    'sectionCode' => $Section->Code
                ]
            );

            $results['analyzed']['sections']++;

            if (!count($Section->Students) && empty($Job->Config['includeEmptySections'])) {
                $results['skipped']['sections']['no-students']++;
                $Job->notice(
                    'Skipping section {sectionCode} with no students.',
                    [
                        'sectionCode' => $Section->Code
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
                'ExternalKey' => 'course[id]'
            ];

            if (!$CourseMapping = Mapping::getByWhere($courseMappingData)) {
                if ($pretend) {
                    $results['created']['courses']++;
                    $Job->notice(
                        'Created canvas course for {sectionTitle} ({sectionCode})',
                        [
                            'sectionTitle' => $sectionTitle,
                            'sectionCode' => $Section->Code
                        ]
                    );
                } else {
                    $canvasResponse = CanvasAPI::createCourse([
                        'account_id' => CanvasAPI::$accountID,
                        'course[name]' => $sectionTitle,
                        'course[course_code]' => $Section->Code,
                        'course[start_at]' => $Section->Term->StartDate,
                        'course[end_at]' => $Section->Term->EndDate,
                        'course[sis_course_id]' => $Section->Code
                    ]);

                    $Job->debug(
                        'Attempting to create canvas course for {sectionTitle} ({sectionCode}).',
                        [
                            'sectionTitle' => $sectionTitle,
                            'sectionCode' => $Section->Code,
                            'canvasResponse' => $canvasResponse
                        ]
                    );

                    if (empty($canvasResponse['id'])) {
                        $results['failed']['courses']++;
                        $Job->error(
                            'Failed to create canvas course for {sectionTitle} ({sectionCode})',
                            [
                                'sectionTitle' => $sectionTitle,
                                'sectionCode' => $Section->Code
                            ]
                        );
                        continue;
                    } else {
                        $courseMappingData['ExternalIdentifier'] = $canvasResponse['id'];
                        $CourseMapping = Mapping::create($courseMappingData, true);
                        $results['created']['courses']++;

                        $Job->notice(
                            'Created canvas section for course {sectionTitle} ({sectionCode}), saved mapping to new canvas course #{canvasCourseExternalId}',
                            [
                                'sectionTitle' => $sectionTitle,
                                'sectionCode' => $Section->Code,
                                'canvasCourseExternalId' => $canvasResponse['id'],
                                'canvasResponse' => $canvasResponse
                            ]
                        );
                    }
                }
            } else {
                $results['existing']['courses']++;

                // update user if mapping exists
                $Job->debug(
                    'Found mapping to Canvas course {canvasCourseExternalId}, checking for updates...',
                    [
                        'canvasCourseExternalId' => $CourseMapping->ExternalIdentifier
                    ]
                );

                try {
                    $canvasCourse = CanvasAPI::getCourse($CourseMapping->ExternalIdentifier);
                } catch (RuntimeException $e) {
                    $results['failed']['courses']++;
                    $Job->error(
                        'Failed to fetch Canvas course {canvasId}: {canvasError} (status: {canvasStatus})',
                        [
                            'canvasId' => $CourseMapping->ExternalIdentifier,
                            'canvasError' => $e->getMessage(),
                            'canvasStatus' => $e->getCode()
                        ]
                    );
                    continue;
                }

                $canvasFrom = [];
                $canvasTo = [];

                if ($canvasCourse['name'] != $sectionTitle) {
                    $canvasFrom['course[name]'] = $canvasCourse['name'];
                    $canvasTo['course[name]'] = $sectionTitle;
                }

                if ($canvasCourse['course_code'] != $Section->Code) {
                    $canvasFrom['course[course_code]'] = $canvasCourse['course_code'];
                    $canvasTo['course[course_code]'] = $Section->Code;
                }

                if ($canvasCourse['sis_course_id'] != $Section->Code) {
                    $canvasFrom['course[sis_course_id]'] = $canvasCourse['sis_course_id'];
                    $canvasTo['course[sis_course_id]'] = $Section->Code;
                }

                if (strpos($canvasCourse['start_at'], $Section->Term->StartDate) !== 0) {
                    $canvasFrom['course[start_at]'] = $canvasCourse['start_at'];
                    $canvasTo['course[start_at]'] = $Section->Term->StartDate;
                }

                if (strpos($canvasCourse['end_at'], $Section->Term->EndDate) !== 0) {
                    $canvasFrom['course[end_at]'] = $canvasCourse['end_at'];
                    $canvasTo['course[end_at]'] = $Section->Term->EndDate;
                }

                if (!empty($Job->Config['canvasTerm']) && $canvasCourse['enrollment_term_id'] != $Job->Config['canvasTerm']) {
                    $canvasFrom['course[term_id]'] = $canvasCourse['enrollment_term_id'];
                    $canvasTo['course[term_id]'] = $Job->Config['canvasTerm'];
                }

                if (empty($canvasTo)) {
                    $Job->debug(
                        'Canvas course data for {canvasCourseCode} matches Slate course.',
                        [
                            'canvasCourseCode' => $Section->Code
                        ]
                    );
                } else {
                    $results['updated']['courses']++;

                    $changes = [];
                    foreach ($canvasTo as $field => $to) {
                        $changes[$field] = [
                            'from' => $canvasFrom[$field],
                            'to' => $to
                        ];
                    }

                    $Job->notice('Updating course {canvasCourseCode}', [
                        'action' => 'update',
                        'changes' => $changes,
                        'canvasCourseCode' => $Section->Code
                    ]);

                    if (!$pretend) {
                        $canvasResponse = CanvasAPI::updateCourse($CourseMapping->ExternalIdentifier, $canvasTo);
                        $Job->debug(
                            'Canvas course {canvasCourseCode} updated',
                            [
                                'canvasCourseCode' => $Section->Code,
                                'canvasResponse' => $canvasResponse
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
                'ExternalKey' => 'course_section[id]'
            ];

            if (!$SectionMapping = Mapping::getByWhere($sectionMappingData)) {
                if ($pretend) {
                    $results['created']['sections']++;
                    $Job->notice(
                        'Created canvas section for {sectionTitle}',
                        [
                            'sectionTitle' => $sectionTitle
                        ]
                    );
                } else {
                    $canvasResponse = CanvasAPI::createSection($CourseMapping->ExternalIdentifier, [
                        'course_section[name]' => $sectionTitle,
                        'course_section[start_at]' => $Section->Term->StartDate,
                        'course_section[end_at]' => $Section->Term->EndDate,
                        'course_section[sis_section_id]' => $Section->Code
                    ]);

                    $Job->debug(
                        'Attempting to create canvas section for {sectionTitle} ({sectionCode}).',
                        [
                            'sectionTitle' => $sectionTitle,
                            'sectionCode' => $Section->Code,
                            'canvasResponse' => $canvasResponse
                        ]
                    );

                    if (empty($canvasResponse['id'])) {
                        $results['failed']['sections']++;
                        $Job->error(
                            'Failed to create canvas section',
                            [
                                'canvasResponse' => $canvasResponse
                            ]
                        );
                        continue;
                    } else {
                        $sectionMappingData['ExternalIdentifier'] = $canvasResponse['id'];
                        $SectionMapping = Mapping::create($sectionMappingData, true);

                        $results['created']['sections']++;
                        $Job->notice(
                            'Created canvas section for {sectionTitle}, saved mapping to new canvas section #{canvasSectionExternalId}',
                            [
                                'sectionTitle' => $sectionTitle,
                                'canvasSectionExternalId' => $canvasResponse['id'],
                                'canvasResponse' => $canvasResponse
                            ]
                        );
                    }
                }
            } else {
                $results['existing']['sections']++;

                // update user if mapping exists
                $Job->debug(
                    'Found mapping to Canvas section {canvasSectionExternalId}, checking for updates...',
                    [
                        'canvasSectionExternalId' => $SectionMapping->ExternalIdentifier
                    ]
                );

                $canvasSection = CanvasAPI::getSection($SectionMapping->ExternalIdentifier);

                $canvasFrom = [];
                $canvasTo = [];

                if ($canvasSection['name'] != $sectionTitle) {
                    $canvasFrom['course_section[name]'] = $canvasSection['name'];
                    $canvasTo['course_section[name]'] = $sectionTitle;
                }

                if ($canvasSection['sis_section_id'] != $Section->Code) {
                    $canvasFrom['course_section[sis_section_id]'] = $canvasSection['sis_section_id'];
                    $canvasTo['course_section[sis_section_id]'] = $Section->Code;
                }

                if (strpos($canvasSection['start_at'], $Section->Term->StartDate) !== 0) {
                    $canvasFrom['course_section[start_at]'] = $canvasSection['start_at'];
                    $canvasTo['course_section[start_at]'] = $Section->Term->StartDate;
                }

                if (strpos($canvasSection['end_at'], $Section->Term->EndDate) !== 0) {
                    $canvasFrom['course_section[end_at]'] = $canvasSection['end_at'];
                    $canvasTo['course_section[end_at]'] = $Section->Term->EndDate;
                }

                if (empty($canvasTo)) {
                    $Job->debug(
                        'Canvas section {sectionTitle} matches Slate section.',
                        [
                            'sectionTitle' => $sectionTitle
                        ]
                    );
                } else {
                    if (!$pretend) {
                        $canvasResponse = CanvasAPI::updateSection($SectionMapping->ExternalIdentifier, $canvasTo);
                        $Job->debug(
                            'Canvas update section response for section: {sectionTitle}',
                            [
                                'sectionTitle' => $sectionTitle,
                                'canvasResponse' => $canvasResponse
                            ]
                        );
                    }

                    $changes = [];
                    foreach ($canvasTo as $field => $to) {
                        $changes[$field] = [
                            'from' => $canvasFrom[$field],
                            'to' => $to
                        ];
                    }

                    $Job->notice('Updating section {sectionTitle}', [
                        'action' => 'update',
                        'changes' => $changes,
                        'sectionTitle' => $sectionTitle
                    ]);

                    $results['updated']['sections']++;
                }
            }


            // sync enrollments
            if (!empty($Job->Config['syncParticiants'])) {
                $canvasEnrollments = $slateEnrollments = [
                    'teachers' => [],
                    'students' => [],
                    'observers' => [],
                    'assistants' => []
                ];

                // get all current canvas enrollments, sort by type and index by username
                foreach (CanvasAPI::getEnrollmentsBySection($SectionMapping->ExternalIdentifier) as $canvasEnrollment) {
                    if ($canvasEnrollment['type'] == 'TeacherEnrollment') {
                        $canvasEnrollments['teachers'][$canvasEnrollment['user']['sis_user_id']] = $canvasEnrollment;
                    } elseif ($canvasEnrollment['type'] == 'StudentEnrollment') {
                        $canvasEnrollments['students'][$canvasEnrollment['user']['sis_user_id']] = $canvasEnrollment;
                    } elseif ($canvasEnrollment['type'] == 'ObserverEnrollment') {
                        $canvasEnrollments['observers'][$canvasEnrollment['user']['sis_user_id']] = $canvasEnrollment;
                    } elseif ($canvasEnrollment['type'] == 'TaEnrollment') {
                        $canvasEnrollments['assistants'][$canvasEnrollment['user']['sis_user_id']] = $canvasEnrollment;
                    }
                }

                // get all current slate enrollments, sort by type and index by username
                foreach (SectionParticipant::getAllByField('CourseSectionID', $Section->ID) as $SectionParticipant) {
                    switch ($SectionParticipant->Role) {
                        case 'Observer':
                            $slateEnrollments['observers'][$SectionParticipant->Person->Username] = $SectionParticipant;
                            break;
                        case 'Student':
                            $slateEnrollments['students'][$SectionParticipant->Person->Username] = $SectionParticipant;
                            break;
                        case 'Assistant':
                            $slateEnrollments['assistants'][$SectionParticipant->Person->Username] = $SectionParticipant;
                            break;
                        case 'Teacher':
                            $slateEnrollments['teachers'][$SectionParticipant->Person->Username] = $SectionParticipant;
                            break;
                    }
                }

                // add teachers to canvas
                foreach ($slateEnrollments['teachers'] as $teacherUsername => $SectionParticipant) {
                    $results['analyzed']['enrollments']++;

                    // check if teacher needs enrollment
                    $enrollTeacher = !array_key_exists($teacherUsername, $canvasEnrollments['teachers']);

                    if ($enrollTeacher) {
                        if (!$pretend) {
                            try {
                                $newEnrollment = static::createSectionEnrollment(
                                    $SectionParticipant->Person,
                                    $SectionMapping,
                                    $Job,
                                    'teacher'
                                );
                                $results['created']['enrollments']++;
                            } catch (SyncException $e) {
                                $Job->logException($e);
                            }
                        } else {
                            $Job->notice(
                                'Enrolling {enrollmentType} {slateUsername} into course section {sectionCode}',
                                [
                                    'sectionCode' => $Section->Code,
                                    'slateUsername' => $teacherUsername,
                                    'enrollmentType' => 'teacher'
                                ]
                            );
                        }
                    }
                }
                // add teacher assistants
                foreach ($slateEnrollments['assistants'] as $teacherUsername => $SectionParticipant) {
                    $results['analyzed']['enrollments']++;

                    // check if teacher needs enrollment
                    $enrollTeacher = !array_key_exists($teacherUsername, $canvasEnrollments['assistants']);

                    if ($enrollTeacher) {
                        if (!$pretend) {
                            try {
                                $newEnrollment = static::createSectionEnrollment(
                                    $SectionParticipant->Person,
                                    $SectionMapping,
                                    $Job,
                                    'ta'
                                );
                                $results['created']['enrollments']++;
                            } catch (SyncException $e) {
                                $Job->logException($e);
                            }
                        } else {
                            $Job->notice(
                                'Enrolling {enrollmentType} {slateUsername} into course section {sectionCode}',
                                [
                                    'sectionCode' => $Section->Code,
                                    'slateUsername' => $teacherUsername,
                                    'enrollmentType' => 'ta'
                                ]
                            );
                        }
                    }
                }

                // remove teachers from canvas
                if (!empty($Job->Config['removeTeachers'])) {
                    $teachersToRemove = array_diff(
                        array_keys($canvasEnrollments['teachers']),
                        array_keys($slateEnrollments['teachers'])
                    );

                    $assistantTeachersToRemove = array_diff(
                        array_keys($canvasEnrollments['assistants']),
                        array_keys($slateEnrollments['assistants'])
                    );

                    foreach ($teachersToRemove as $teacherUsername) {
                        $enrollmentId = $canvasEnrollments['teachers'][$teacherUsername]['id'];

                        if ($Teacher = User::getByUsername($teacherUsername)) { // todo: handle accounts deleted in slate?
                            if (!$pretend) {
                                try {
                                    $removedEnrollment = static::removeSectionEnrollment(
                                        $Teacher,
                                        $SectionMapping,
                                        $Job,
                                        'teacher',
                                        $enrollmentId
                                    );
                                } catch (SyncException $e) {
                                    $Job->logException($e);
                                }
                            } else {
                                $Job->notice(
                                    'Removing {enrollmentType} enrollment for {slateUsername} from course section {sectionCode}',
                                    [
                                        'sectionCode' => $Section->Code,
                                        'slateUsername' => $teacherUsername,
                                        'enrollmentType' => 'teacher'
                                    ]
                                );
                            }
                        }
                    }

                    foreach ($assistantTeachersToRemove as $teacherUsername) {
                        $enrollmentId = $canvasEnrollments['assistants'][$teacherUsername]['id'];

                        if ($Teacher = User::getByUsername($teacherUsername)) { // todo: handle accounts deleted in slate?
                            if (!$pretend) {
                                try {
                                    $removedEnrollment = static::removeSectionEnrollment(
                                        $Teacher,
                                        $SectionMapping,
                                        $Job,
                                        'ta',
                                        $enrollmentId
                                    );
                                } catch (SyncException $e) {
                                    $Job->logException($e);
                                }
                            } else {
                                $Job->notice(
                                    'Removing {enrollmentType} enrollment for {slateUsername} from course section {sectionCode}',
                                    [
                                        'sectionCode' => $Section->Code,
                                        'slateUsername' => $teacherUsername,
                                        'enrollmentType' => 'ta'
                                    ]
                                );
                            }
                        }
                    }
                }

                // add students to canvas
                foreach ($slateEnrollments['students'] as $studentUsername => $SectionParticipant) {
                    $results['analyzed']['enrollments']++;

                    $enrollStudent = !array_key_exists($studentUsername, $canvasEnrollments['students']);

                    if ($SectionParticipant->StartDate) {
                        $startAt = CanvasAPI::formatTimestamp($SectionParticipant->StartDate);
                    } else {
                        $startAt = null;
                    }

                    if ($SectionParticipant->EndDate) {
                        // treat "empty" time component as end of day
                        if (date('H:i:s', $SectionParticipant->EndDate) == '00:00:00') {
                            $endAt = CanvasAPI::formatTimestamp($SectionParticipant->EndDate + (60*60*24-1));
                        } else {
                            $endAt = CanvasAPI::formatTimestamp($SectionParticipant->EndDate);
                        }

                        // end_at will be ignored if start_at isn't set too
                        if (!$startAt) {
                            $startAt = CanvasAPI::formatTimestamp(strtotime($Section->Term->StartDate));
                        }
                    } else {
                        $endAt = null;
                    }

                    if ($enrollStudent) {
                        if (!$pretend) {
                            try {
                                $newEnrollment = static::createSectionEnrollment(
                                    $SectionParticipant->Person,
                                    $SectionMapping,
                                    $Job,
                                    'student',
                                    [
                                        'start_at' => $startAt,
                                        'end_at' => $endAt
                                    ]
                                );
                            } catch (SyncException $e) {
                                $Job->logException($e);
                            }
                        } else {
                            $Job->notice(
                                'Enrolling {enrollmentType} {slateUsername} into course section {sectionCode}',
                                [
                                    'sectionCode' => $Section->Code,
                                    'slateUsername' => $studentUsername,
                                    'enrollmentType' => 'student'
                                ]
                            );
                        }
                    } else {
                        $canvasEnrollment = $canvasEnrollments['students'][$studentUsername];
                        $changes = [];

                        if ($canvasEnrollment['start_at'] != $startAt) {
                            $changes['start_at'] = [
                                'from' => $canvasEnrollment['start_at'],
                                'to' => $startAt
                            ];
                        }

                        if ($canvasEnrollment['end_at'] != $endAt) {
                            $changes['end_at'] = [
                                'from' => $canvasEnrollment['end_at'],
                                'to' => $endAt
                            ];
                        }

                        if (!empty($changes)) {
                            $Job->notice(
                                'Updating enrollment for {enrollmentType} {slateUsername} in course section {sectionCode}',
                                [
                                    'action' => 'update',
                                    'changes' => $changes,
                                    'sectionCode' => $Section->Code,
                                    'slateUsername' => $studentUsername,
                                    'enrollmentType' => 'student'
                                ]
                            );

                            if (!$pretend) {
                                try {
                                    $updatedEnrollment = static::createSectionEnrollment(
                                        $SectionParticipant->Person,
                                        $SectionMapping,
                                        $Job,
                                        'student',
                                        [
                                            'start_at' => $startAt,
                                            'end_at' => $endAt
                                        ]
                                    );
                                } catch (SyncException $e) {
                                    $Job->logException($e);
                                }
                            }

                            $results['updated']['sections']++;
                        }
                    }

                    if ($SectionParticipant->EndDate && !empty($Job->Config['concludeEndedEnrollments'])) {
                        if ($SectionParticipant->EndDate < $now) {
                            $Job->notice(
                                'Concluding ended enrollment {enrollmentType} for {slateUsername} in course section {sectionCode}',
                                [
                                    'sectionCode' => $Section->Code,
                                    'slateUsername' => $studentUsername,
                                    'enrollmentType' => 'student'
                                ]
                            );

                            if (!$pretend) {
                                try {
                                    $concludedEnrollment = static::removeSectionEnrollment(
                                        $SectionParticipant->Person,
                                        $SectionMapping,
                                        $Job,
                                        'student',
                                        $canvasEnrollment['id'],
                                        'conclude'
                                    );
                                } catch (SyncException $e) {
                                    $Job->logException($e);
                                }
                            }
                        }
                    }

                    // push enrollments for guardians
                    if (!empty($Job->Config['syncObservers'])) {
                        // $studentGuardians = $Student->getValue('Guardians');
                        // if (is_array($studentGuardians) && !empty($studentGuardians)) {
                        //     $studentCanvasId = static::_getCanvasUserID($Student->ID);
                        //     foreach ($studentGuardians as $Guardian) {
                        //         $slateEnrollments['observers'][] = $Guardian->Username;

                        //         $guardianMappingData = [
                        //             'ContextClass' => $Guardian->getRootClass(),
                        //             'ContextID' => $Guardian->ID,
                        //             'Connector' => static::getConnectorId(),
                        //             'ExternalKey' => 'user[id]'
                        //         ];

                        //         // only enroll guardians that have logged into slate
                        //         if ($GuardianMapping = Mapping::getByWhere($guardianMappingData)) {
                        //             $enrollGuardian = !array_key_exists($Guardian->Username, $canvasEnrollments['observers']);

                        //             if ($enrollGuardian) {
                        //                 if (!$pretend) {
                        //                     $observerPushResults = static::_logSyncResults(
                        //                         $Job,
                        //                         $results,
                        //                         static::createSectionEnrollment(
                        //                             $Guardian,
                        //                             $CourseMapping,
                        //                             $SectionMapping,
                        //                             'observer',
                        //                             [
                        //                                 'associated_user_id' => $studentCanvasId
                        //                             ]
                        //                         )
                        //                     );
                        //                 } else {
                        //                     $Job->log("Creating observer enrollment for $Guardian->Username observing $Student->Username in course section $Section->Code", LogLevel::NOTICE);
                        //                 }
                        //             }
                        //         }
                        //     }
                        // }
                    }
                }
                // remove observer enrollments
                if (!empty($Job->Config['syncObservers'])) {
                    // foreach (array_diff(array_keys($canvasEnrollments['observers']), $slateEnrollments['observers']) AS $observerUsername) {
                    //     $enrollmentId = $canvasEnrollments['observers'][$observerUsername]['id'];
                    //     if ($Observer = User::getByUsername($observerUsername)) { // todo: handle accounts deleted in slate?
                    //         if (!$pretend) {
                    //             $results = static::_logSyncResults(
                    //                 $Job,
                    //                 $results,
                    //                 static::removeSectionEnrollment(
                    //                     $Observer,
                    //                     $CourseMapping,
                    //                     $SectionMapping,
                    //                     [
                    //                         'type' => 'observer',
                    //                         'enrollmentId' => $enrollmentId,
                    //                         'enrollmentTask' => 'delete'
                    //                     ]
                    //                 )
                    //             );
                    //         } else {
                    //             $Job->log("Removing observer enrollment for $Observer->Username from course section $Section->Code", LogLevel::NOTICE);
                    //         }
                    //     }
                    // }
                }

                // remove students from canvas
                $studentsToRemove = array_diff(
                    array_keys($canvasEnrollments['students']),
                    array_keys($slateEnrollments['students'])
                );

                foreach ($studentsToRemove as $studentUsername) {
                    $enrollmentId = $canvasEnrollments['students'][$studentUsername]['id'];

                    if ($Student = Student::getByUsername($studentUsername)) { // todo: handle accounts deleted in slate?
                        if (!$pretend) {
                            try {
                                $removedEnrollment = static::removeSectionEnrollment(
                                    $Student,
                                    $SectionMapping,
                                    $Job,
                                    'student',
                                    $enrollmentId
                                );
                            } catch (SyncException $e) {
                                $Job->logException($e);
                            }
                        } else {
                            $Job->notice(
                                'Removing {enrollmentType} enrollment for {slateUsername} from course section {sectionCode}',
                                [
                                    'sectionCode' => $Section->Code,
                                    'slateUsername' => $studentUsername,
                                    'enrollmentType' => 'student'
                                ]
                            );
                        }
                    }
                }

            } // end syncParticiants

        } // end sections loop

        return $results;
    }

    /**
    * Create enrollment in canvas for user.
    * @param $User User object - User to enroll
    * @param $CourseMapping Mapping object - Mapping to canvas course to enroll user to
    * @param $SectionMapping Mapping object - Mapping to canvas course section to enroll user to
    * @param $settings array - Config array containing enrollment options
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
                    'apiResponse' => $canvasResponse

                ]
            );
        } catch (\Exception $e) {
            throw new SyncException(
                'Failed to create {enrollmentType} enrollment for {slateUsername} - {sectionCode} in Canvas',
                [
                    'enrollmentType' => $enrollmentType,
                    'slateUsername' => $User->Username,
                    'sectionCode' => $SectionMapping->Context->Code,
                    'exception' => $e
                ]
            );
        }

        return new SyncResult(
            SyncResult::STATUS_CREATED,
            'Enrolled {enrollmentType} {slateUsername} in {slateSectionCode}',
            [
                'slateUsername' => $User->Username,
                'enrollmentType' => $enrollmentType,
                'slateSectionCode' => $SectionMapping->Context->Code
            ]
        );
    }

    /**
    * Remove enrollment in canvas for user.
    * @param $User User object - User to unenroll
    * @param $CourseMapping Mapping object - Mapping to canvas course to unenroll user to
    * @param $SectionMapping Mapping object - Mapping to canvas course section to unenroll user to
    * @param $settings array - Config array containing unenrollment options
    * @return SyncResult object / SyncException object
    */
    protected static function removeSectionEnrollment(IPerson $User, Mapping $SectionMapping, LoggerInterface $logger, $enrollmentType, $enrollmentId, $enrollmentTask = 'conclude')
    {
        if (
            $enrollmentType != 'student'
            && $enrollmentType != 'teacher'
            && $enrollmentType != 'observer'
            && $enrollmentType != 'ta'
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
                $canvasSection ? $canvasSection['course_id'] : $CourseMapping->ExternalIdentifier,
                $enrollmentId,
                $enrollmentTask
            );

            $logger->debug(
                'Removing {enrollmentType} enrollment for {slateUsername} in {sectionCode}',
                [
                    'enrollmentType' => ucfirst($enrollmentType).'Enrollment',
                    'slateUsername' => $User->Username,
                    'sectionCode' => $SectionMapping->Context->Code,
                    'apiResponse' => $canvasResponse

                ]
            );
        } catch (\Exception $e) {
            throw new SyncException(
                'Unable to delete {enrollmentType} enrollment for {slateUsername} in {sectionCode}',
                [
                    'enrollmentType' => ucfirst($enrollmentType).'Enrollment',
                    'slateUsername' => $User->Username,
                    'slateSectionCode' => $SectionMapping->Context->Code,
                    'exception' => $e
                ]
            );
        }

        return new SyncResult(
            SyncResult::STATUS_DELETED,
            'Deleted {enrollmentType} enrollment for {slateUsername} in {sectionCode}',
            [
                'sectionCode' => $SectionMapping->Context->Code,
                'slateUsername' => $User->Username,
                'enrollmentType' => $enrollmentType

            ]
        );
    }

    /**
    *  protected methods
    */
    protected static function _getCanvasUserID($userId)
    {
        static $cache = [];

        if (!array_key_exists($userId, $cache)) {
            $UserMapping = Mapping::getByWhere([
                'ContextClass' => User::getStaticRootClass(),
                'ContextID' => $userId,
                'Connector' => static::getConnectorId(),
                'ExternalKey' => 'user[id]'
            ]);

            if (!$UserMapping) {
                throw new \Exception("Could not find canvas mapping for user #$userId");
            }

            $cache[$userId] = $UserMapping->ExternalIdentifier;
        }

        return $cache[$userId];
    }
}
