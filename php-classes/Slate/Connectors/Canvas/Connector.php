<?php

namespace Slate\Connectors\Canvas;

use Psr\Log\LogLevel;
use Psr\Log\LoggerInterface;

use RemoteSystems\Canvas AS CanvasAPI;

use Emergence\EventBus;

use Emergence\Connectors\Job;
use Emergence\Connectors\Mapping;
use Emergence\People\IPerson;
use Emergence\People\Person;
use Emergence\People\User;
use Emergence\Util\Data AS DataUtil;
use Emergence\Connectors\SAML2;
use Emergence\Connectors\SyncResult;
use Emergence\Connectors\Exceptions\SyncException;

use Slate\Term;
use Slate\Courses\Section;
use Slate\Courses\SectionParticipant;
use Slate\People\Student;

class Connector extends \Emergence\Connectors\AbstractConnector implements \Emergence\Connectors\ISynchronize, \Emergence\Connectors\IIdentityConsumer
{
    use \Emergence\Connectors\IdentityConsumerTrait {
        getSAMLNameId as getDefaultSAMLNameId;
    }

    public static $title = 'Canvas';
    public static $connectorId = 'canvas';

    public static $defaultLogger;

    /**
    * IdentityConsumer interface methods
    */
    public static function handleLoginRequest(IPerson $Person)
    {
        EventBus::fireEvent('beforelogin', ['Slate', 'Connectors', 'Canvas'], [
            'Person' => $Person
        ]);

        return SAML2::handleLoginRequest($Person, __CLASS__);
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
        EventBus::fireEvent('beforeauthenticate', ['Slate', 'Connectors', 'Canvas'], [
            'Person' => $Person
        ]);

        try {
            $logger = static::getDefaultLogger();

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
            if(false === call_user_func(static::$beforeAuthenticate, $Person)) {
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

        return static::getDefaultSAMLNameId($Person);
    }

    // workflow implementations
    protected static function _getJobConfig(array $requestData)
    {
        $config = parent::_getJobConfig($requestData);

        $config['pushUsers'] = !empty($requestData['pushUsers']);
        $config['pushSections'] = !empty($requestData['pushSections']);
        $config['removeTeachers'] = !empty($requestData['removeTeachers']);

        return $config;
    }

    public static function synchronize(Job $Job, $pretend = true)
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

    public static function pushUsers(Job $Job, $pretend = true)
    {
        $conditions = [
            'AccountLevel IS NOT NULL AND ( (AccountLevel NOT IN ("Disabled", "Contact", "User", "Staff")) OR (Class = "Slate\\\\People\\\\Student" AND AccountLevel != "Disabled") )', // no disabled accounts, non-users, guests, and non-teaching staff
            'GraduationYear IS NULL OR GraduationYear >= ' . Term::getClosestGraduationYear(), // no alumni
            'Username IS NOT NULL' // username must be assigned
        ];

        $results = [
            'analyzed' => 0,
            'existing' => 0,
            'skipped' => 0,
            'failed' => 0,
            'logins' => [
                'updated' => 0
            ]
        ];

        foreach (User::getAllByWhere($conditions) AS $User) {
            $Job->log(
                LogLevel::DEBUG,
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
                } else if ($syncResult->getStatus() === SyncResult::STATUS_UPDATED) {
                    $results['updated']++;
                    $results['existing']++;
                    if ($syncResult->getContext('updateContext') == 'login') {
                        $results['logins']['updated']++;
                    }
                } else if ($syncResult->getStatus() === SyncResult::STATUS_SKIPPED) {
                    continue;
                }
            } catch (SyncException $e) {
                $Job->logException($e);
                $results['failed']++;
            }

            try {
                $enrollmentResult = static::pushEnrollments($User, $Job, $pretend);
            } catch (SyncException $e) {
                $Job->logException($e);
            }
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
        if (!$logger) {
            $logger = static::getDefaultLogger();
        }

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
            $logger->log(
                LogLevel::DEBUG,
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

            if ($canvasUser['name'] != $User->FullName) {
                $canvasUserChanges['user[name]'] = [
                    'from' => $canvasUser['name'],
                    'to' => $User->FullName
                ];
            }

            $shortName = $User->PreferredName ?: $User->FirstName;
            if ($canvasUser['short_name'] != $shortName) {
                $canvasUserChanges['user[short_name]'] = [
                    'from' => $canvasUser['short_name'],
                    'to' => $shortName
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
                    $logger->log(
                        LogLevel::DEBUG,
                        'Updating canvas for user {slateUsername}',
                        [
                            'slateUsername' => $User->Username,
                            'canvasUserChanges' => $canvasUserChanges,
                            'canvasResponse' => $canvasResponse
                        ]
                    );
                    //$Job->log('<blockquote>Canvas update user response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                }

                $logger->log(
                    LogLevel::DEBUG,
                    'Updated user {slateUsername}',
                    [
                        'slateUsername' => $User->Username
                    ]
                );
            } else {
                $logger->log(
                    LogLevel::DEBUG,
                    'Canvas user matches Slate user {slateUsername}',
                    [
                        'slateUsername' => $User->Username
                    ]
                );
            }

            // sync login
            if (!empty($canvasLoginChanges)) {
                $changes['login'] = $canvasLoginChanges;
                if (!$pretend) {
                    $canvasResponse = CanvasAPI::updateLogin($logins[0]['id'], DataUtil::extractToFromDelta($canvasLoginChanges));
                    $logger->log(
                        LogLevel::DEBUG,
                        'Updated canvas login for user {slateUsername}',
                        [
                            'slateUsername' => $User->Username,
                            'canvasLoginChanges' => $canvasLoginChanges,
                            'canvasResponse' => $canvasResponse
                        ]
                    );
                    //$Job->log('<blockquote>Canvas update login response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                }
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

                $logger->log(
                    LogLevel::DEBUG,
                    'Updated login for user {slateUsername}',
                    [
                        'slateUsername' => $User->Username,
                        'changes' => $changes['login']
                    ]
                );
            } else {
                $logger->log(
                    LogLevel::DEBUG,
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
                $logger->log(
                    LogLevel::NOTICE,
                    'Created canvas user for {slateUsername})',
                    [
                        'slateUsername' => $User->Username
                    ]
                );

                return new SyncResult(
                    SyncResult::STATUS_CREATED,
                    'Created canvas user for {slateUsername}, savedmapping to new canvas user (pretend-mode)',
                    [
                        'slateUsername' => $User->Username
                    ]
                );
            }

            $canvasResponse = CanvasAPI::createUser([
                'user[name]' => $User->FullName,
                'user[short_name]' => $User->FirstName,
                'pseudonym[unique_id]' => $User->Email,
                'pseudonym[sis_user_id]' => $User->Username,
                'communication_channel[type]' => 'email',
                'communication_channel[address]' => $User->Email
            ]);

            $logger->log(
                LogLevel::DEBUG,
                'Creating canvas user for {slateUsername}',
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
                case 'Teacher':
                case 'Assistant':
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
                } else if ($syncResult->getStatus() === SyncResult::STATUS_VERIFIED) {
                    $results['verified']++;
                } else if ($syncResult->getStatus() === SyncResult::STATUS_UPDATED) {
                    $results['updated']++;
                } else if ($syncResult->getStatus() === SyncResult::STATUS_SKIPPED) {
                    $nesults['skipped']++;
                } else if ($syncResult->getStatus() === SyncResult::STATUS_DELETED) {
                    $results['removed']++;
                }

            } catch (SyncException $e) {
                $logger->log(
                    LogLevel::ERROR,
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

            $WardEnrollments = SectionParticipant::getAllByQuery(
                'SELECT SectionParticipant.* '.
                '  FROM `%s` SectionParticipant '.
                '  JOIN `%s` Section '.
                '    ON Section.ID = SectionParticipant.CourseSectionID '.
                ' WHERE Section.TermID = %u '.
                '   AND SectionParticipant.PersonID = %u ',
                [
                    \Slate\Courses\SectionParticipant::$tableName,
                    Section::$tableName,
                    Term::getClosest()->ID,
                    $Ward->ID
                ]
            );

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
                    } else if ($canvasEnrollment->getStatus() === SyncResult::STATUS_VERIFIED) {
                        $results['verified']++;
                    } else if ($canvasEnrollment->getStatus() === SyncResult::STATUS_UPDATED) {
                        $results['updated']++;
                    } else if ($canvasEnrollment->getStatus() === SyncResult::STATUS_SKIPPED) {
                        $nesults['skipped']++;
                    } else if ($canvasEnrollment->getStatus() === SyncResult::STATUS_DELETED) {
                        $results['removed']++;
                    }

                } catch (SyncException $e) {
                    $logger->log(
                        LogLevel::ERROR,
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
        if (!$logger) {
            $logger = static::getDefaultLogger();
        }

        // index canvas enrollments by course_section_id
        $canvasEnrollments = [];
        foreach (CanvasAPI::getEnrollmentsByUser(static::_getCanvasUserId($User->ID)) as $canvasUserEnrollment) {
            $canvasEnrollments[$canvasUserEnrollment['course_section_id']] = $canvasUserEnrollment;
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

            if (!array_key_exists($SectionMapping->ExternalIdentifier, $canvasEnrollments)) {
                // create section enrollment
                if ($pretend) {
                    return new SyncResult(
                        SyncResult::STATUS_CREATED,
                        'Enrolled {enrollmentType} {slateUsername} in {slateSectionCode} (pretend-mode)',
                        [
                            'slateUsername' => $User->Username,
                            'enrollmentType' => $enrollmentType,
                            'slateSectionCode' => $SectionMapping->Context->Code
                        ]
                    );
                }

                return static::createSectionEnrollment(
                    $User,
                    $SectionMapping,
                    $logger,
                    $enrollmentType,
                    $observeeId
                );

            } elseif (ucfirst($enrollmentType).'Enrollment' != $canvasEnrollments[$SectionMapping->ExternalIdentifier]['type']) {
                if ($pretend) {
                    return new SyncResult(
                        SyncResult::STATUS_UPDATED,
                        'Updated enrollment type in {sectionCode} for {slateUsername} from {originalEnrollmentType} -> {enrollmentType} (pretend-mode)',
                        [
                            'sectionCode' => $SectionMapping->Context->Code,
                            'slateUsername' => $User->Username,
                            'originalEnrollmentType' => $canvasEnrollments[$SectionMapping->ExternalIdentifer]['type'],
                            'enrollmentType' => $enrollmentType
                        ]
                    );
                }

                try {
                    $deletedCanvasEnrollment = static::removeSectionEnrollment(
                        $User,
                        $SectionMapping,
                        $logger,
                        strtolower(str_replace('Enrollment', '', $canvasEnrollments[$SectionMapping->ExternalIdentifier]['type'])),
                        $canvasEnrollments[$SectionMapping->ExternalIdentifier]['id']
                    );

                    $createdCanvasEnrollment = static::pushSectionEnrollment(
                        $User,
                        $SectionMapping,
                        $enrollmentType,
                        $logger,
                        $observeeId
                    );


                } catch (SyncException $e) {
                    $logger->log(
                        LogLevel::ERROR,
                        'Unable to update enrollment type for {slateUsername} in {sectionCode} from: {originalEnrollmentType} to: {enrollmentType}',
                        [
                            'slateUsername' => $User->Username,
                            'sectionCode' => $SectionMapping->Context->Code,
                            'originalEnrollmentType' => $canvasEnrollments[$sectionMapping->ExternalIdentifier]['type'],
                            'enrollmentType' => $enrollmentType
                        ]
                    );
                    throw $e;
                }

                return new SyncResult(
                    SyncResult::STATUS_UPDATED,
                    'Updated enrollment type in {sectionCode} for {slateUsername} from {originalEnrollmentType} -> {enrollmentType}',
                    [
                        'sectionCode' => $SectionMapping->Context->Code,
                        'slateUsername' => $User->Username,
                        'originalEnrollmentType' => $canvasEnrollments[$SectionMapping->ExternalIdentifer]['type'],
                        'enrollmentType' => $enrollmentType
                    ]
                );
            } else {
                // TODO: confirm enrollment type
                return new SyncResult(
                    SyncResult::STATUS_VERIFIED,
                    'Verified enrollment in {sectionCode} for {enrollmentType} {slateUsername}',
                    [
                        'sectionCode' => $SectionMapping->Context->Code,
                        'enrollmentType' => $enrollmentType,
                        'slateUsername' => $User->Username
                    ]
                );
            }

        } elseif ($canvasEnrollments[$SectionMapping->ExternalIdentifier]) {
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
    public static function pushSections(Job $Job, $pretend = true)
    {
        $sectionConditions = [
            'TermID' => [
                'values' => Term::getClosest()->getMaster()->getContainedTermIDs(),
                'operator' => 'IN'
            ]
        ];

        $results = [
            'analyzed' => [
                'courses' => 0,
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
                'sections' => 0
            ]

        ];

        foreach (Section::getAllByWhere($sectionConditions) AS $Section) {
            $canvasSection = null;

            // build section title
            // TODO: use configurable formatter
            $sectionTitle = $Section->Title;

            if ($Section->Schedule) {
                $sectionTitle .= ' (' . $Section->Schedule->Title . ')';
            }

            $Job->log(
                LogLevel::NOTICE,
                'Analyzing Slate section {sectionTitle} ({sectionCode})',
                [
                    'sectionTitle' => $sectionTitle,
                    'sectionCode' => $Section->Code
                ]
            );

            $results['analyzed']['sections']++;

            if (!count($Section->Students)) {
                $results['skipped']['sections']++;
                $Job->log(
                    LogLevel::NOTICE,
                    'Skippin gsection {sectionCode} with no students.',
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
                    $Job->log(
                        LogLevel::NOTICE,
                        'Created canvas course for {sectionTitle} ({sectionCode})',
                        [
                            'sectionTitle' => $sectionTitle,
                            'sectionCode' => $Section->Code
                        ]
                    );
                    continue;
                }

                $canvasResponse = CanvasAPI::createCourse([
                    'account_id' => CanvasAPI::$accountID,
                    'course[name]' => $sectionTitle,
                    'course[course_code]' => $Section->Code,
                    'course[start_at]' => $Section->Term->StartDate,
                    'course[end_at]' => $Section->Term->EndDate,
                    'course[sis_course_id]' => $Section->Code
                ]);

                $Job->log(
                    LogLevel::DEBUG,
                    'Attempting to create canvas course for {sectionTitle} ({sectionCode}).',
                    [
                        'sectionTitle' => $sectionTitle,
                        'sectionCode' => $Section->Code,
                        'canvasResponse' => $canvasResponse
                    ]
                );

                if (empty($canvasResponse['id'])) {
                    $results['failed']['courses']++;
                    $Job->log(
                        LogLevel::ERROR,
                        'Failed to create canvas course for {sectionTitle} ({sectionCode})',
                        [
                            'sectionTitle' => $sectionTitle,
                            'sectionCode' => $Section->Code
                        ]
                    );
                    continue;
                }

                $courseMappingData['ExternalIdentifier'] = $canvasResponse['id'];
                $CourseMapping = Mapping::create($courseMappingData, true);
                $results['created']['courses']++;

                $Job->log(
                    LogLevel::NOTICE,
                    'Created canvas section for course {sectionTitle} ({sectionCode}), saved mapping to new canvas course #{canvasCourseExternalId}',
                    [
                        'sectionTitle' => $sectionTitle,
                        'sectionCode' => $Section->Code,
                        'canvasCourseExternalId' => $canvasResponse['id'],
                        'canvasResponse' => $canvasResponse
                    ]
                );

            } else {
                $results['existing']['sections']++;

                // update user if mapping exists
                $Job->log(
                    LogLevel::DEBUG,
                    'Found mapping to Canvas course {canvasCourseExternalId}, checking for updates...',
                    [
                        'canvasCourseExternalId' => $CourseMapping->ExternalIdentifier
                    ]
                );

                $canvasCourse = CanvasAPI::getCourse($CourseMapping->ExternalIdentifier);
                $Job->log(
                    LogLevel::DEBUG,
                    'Canvas course data retrieved from Canvas API',
                    [
                        'canvasResponse' => $canvasCourse
                    ]
                );

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

                if (empty($canvasTo)) {
                    $Job->log(
                        LogLevel::DEBUG,
                        'Canvas course data for {canvasCourseCode} matches Slate course.',
                        [
                            'canvasCourseCode' => $Section->Code
                        ]
                    );
                    continue;
                }

                $results['updated']['courses']++;
                foreach ($canvasTo AS $field => $to) {
                    $Job->log(
                        LogLevel::NOTICE,
                        'Updating values for course field {courseField}\n\tFrom: {fieldPreviousFieldValue}:\t->{courseCurrentFieldValue}\t',
                        [
                            'courseField' => $field,
                            'fieldPreviousFieldValue' => $canvasCourse[$field],
                            'courseCurrentFieldValue' => $to
                        ]
                    );
                }

                if ($pretend) {
                    continue;
                }

                $canvasResponse = CanvasAPI::updateCourse($CourseMapping->ExternalIdentifier, $canvasTo);
                $Job->log(
                    LogLevel::DEBUG,
                    'Canvas course {canvasCourseCode} updated',
                    [
                        'canvasCourseCode' => $Section->Code,
                        'canvasResponse' => $canvasResponse
                    ]
                );
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
                    $Job->log(
                        LogLevel::NOTICE,
                        'Created canvas section for {sectionTitle}',
                        [
                            'sectionTitle' => $sectionTitle
                        ]
                    );
                    continue;
                }

                $canvasResponse = CanvasAPI::createSection($CourseMapping->ExternalIdentifier, [
                    'course_section[name]' => $sectionTitle,
                    'course_section[start_at]' => $Section->Term->StartDate,
                    'course_section[end_at]' => $Section->Term->EndDate,
                    'course_section[sis_section_id]' => $Section->Code
                ]);

                $Job->log(
                    LogLevel::DEBUG,
                    'Attempting to create canvas section for {sectionTitle} ({sectionCode}).',
                    [
                        'sectionTitle' => $sectionTitle,
                        'sectionCode' => $Section->Code,
                        'canvasResponse' => $canvasResponse
                    ]
                );

                if (empty($canvasResponse['id'])) {
                    $results['failed']['sections']++;
                    $Job->log(
                        LogLevel::ERROR,
                        'Failed to create canvas section',
                        [
                            'canvasResponse' => $canvasResponse
                        ]
                    );
                    continue;
                }

                $sectionMappingData['ExternalIdentifier'] = $canvasResponse['id'];
                $SectionMapping = Mapping::create($sectionMappingData, true);

                $results['created']['sections']++;
                $Job->log(
                    LogLevel::NOTICE,
                    'Created canvas section for $sectionTitle, saved mapping to new canvas section #{$canvasResponse[id]}',
                    [
                        'sectionTitle' => $sectionTitle,
                        'canvasSectionExternalId' => $canvasResponse['id'],
                        'canvasResponse' => $canvasResponse
                    ]
                );

            } else {
                $results['existing']['sections']++;

                // update user if mapping exists
                $Job->log(
                    LogLevel::DEBUG,
                    'Found mapping to Canvas section {canvasSectionExternalId}, checking for updates...',
                    [
                        'canvasSectionExternalId' => $SectionMapping->ExternalIdentifier
                    ]
                );

                $canvasSection = CanvasAPI::getSection($SectionMapping->ExternalIdentifier);
                $Job->log(
                    LogLevel::DEBUG,
                    'Retrieved canvas section data from canvas API',
                    [
                        'canvasResponse' => $canvasSection
                    ]
                );

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
                    $Job->log(
                        LogLevel::NOTICE,
                        'Canvas section {sectionTitle} matches Slate section.',
                        [
                            'sectionTitle' => $sectionTitle
                        ]
                    );
                    continue;
                } else {
                    $Job->log(
                        LogLevel::NOTICE,
                        'Found changes for canvas section {sectionTitle}. Updating section in canvas...',
                        [
                            'sectionTitle' => $sectionTitle
                        ]
                    );
                }

                if (!$pretend) {
                    $canvasResponse = CanvasAPI::updateSection($SectionMapping->ExternalIdentifier, $canvasTo);
                    $Job->log(
                        LogLevel::DEBUG,
                        'Canvas update section response for section: {sectionTitle}',
                        [
                            'sectionTitle' => $sectionTitle,
                            'canvasResponse' => $canvasResponse
                        ]
                    );
                }

                $changes = [];
                foreach ($canvasTo AS $field => $to) {
                    $Job->log(
                        LogLevel::NOTICE,
                        '\t{canvasFieldName}\t{canvasFieldOriginalValue}\t->\t{canvasFieldValue}',
                        [
                            'canvasFieldName' => $field,
                            'canvasFieldOriginalValue' => $canvasFrom[$field],
                            'canvasFieldValue' => $to
                        ]
                    );
                }

                $results['sections']['sectionsUpdated']++;
            }

            // sync enrollments
            $canvasEnrollments = $slateEnrollments = [
                'teachers' => [],
                'students' => [],
                'observers' => []
            ];

            // get all enrollments, sort by type and index by username
            foreach (CanvasAPI::getEnrollmentsBySection($SectionMapping->ExternalIdentifier) AS $canvasEnrollment) {
                if ($canvasEnrollment['type'] == 'TeacherEnrollment') {
                    $canvasEnrollments['teachers'][$canvasEnrollment['user']['sis_user_id']] = $canvasEnrollment;
                } elseif ($canvasEnrollment['type'] == 'StudentEnrollment') {
                    $canvasEnrollments['students'][$canvasEnrollment['user']['sis_user_id']] = $canvasEnrollment;
                } elseif ($canvasEnrollment['type'] == 'ObserverEnrollment') {
                    $canvasEnrollments['observers'][$canvasEnrollment['user']['sis_user_id']] = $canvasEnrollment;
                }
            }

            // add teachers to canvas
            foreach ($Section->Teachers AS $Teacher) {
                $slateEnrollments['teachers'][] = $Teacher->Username;
                $results['analyzed']['enrollments']++;
                // check if teacher needs enrollment
                $enrollTeacher = !array_key_exists($Teacher->Username, $canvasEnrollments['teachers']);
                if ($enrollTeacher) {
                    if (!$pretend) {
                        try {
                            $newEnrollment = static::createSectionEnrollment(
                                $Teacher,
                                $SectionMapping,
                                $Job,
                                'teacher'
                            );
                            $results['created']['enrollments']++;
                        } catch (SyncException $e) {
                            $Job->logException($e);
                        }
                    } else {
                        $Job->log(
                            LogLevel::NOTICE,
                            'Enrolling {enrollmentType} {slateUsername} into course section {sectionCode}',
                            [
                                'sectionCode' => $Section->Code,
                                'slateUsername' => $Teacher->Username,
                                'enrollmentType' => 'teacher'
                            ]
                        );
                    }
                }
            }

            // remove teachers from canvas
            if (!empty($Job->Config['removeTeachers'])) {
                foreach (array_diff(array_keys($canvasEnrollments['teachers']), $slateEnrollments['teachers']) AS $teacherUsername) {
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
                            $Job->log(
                                LogLevel::NOTICE,
                                'Removing {enrollmentType} enrollment for {slateUsername} from course section {sectionCode}',
                                [
                                    'sectionCode' => $Section->Code,
                                    'slateUsername' => $Teacher->Username,
                                    'enrollmentType' => 'teacher'
                                ]
                            );
                        }
                    }
                }
            }

            // add students to canvas
            foreach ($Section->Students AS $Student) {
                $slateEnrollments['students'][] = $Student->Username;
                $enrollStudent = !array_key_exists($Student->Username, $canvasEnrollments['students']);

                if ($enrollStudent) {
                    if (!$pretend) {
                        try {
                            $newEnrollment = static::createSectionEnrollment(
                                $Student,
                                $SectionMapping,
                                $Job,
                                'student'
                            );
                        } catch (SyncException $e) {
                            $Job->logException($e);
                        }
                    } else {
                        $Job->log(
                            LogLevel::NOTICE,
                            'Enrolling {enrollmentType} {slateUsername} into course section {sectionCode}',
                            [
                                'sectionCode' => $Section->Code,
                                'slateUsername' => $Student->Username,
                                'enrollmentType' => 'student'
                            ]

                        );
                    }
                }

                // push enrollments for guardians
#                $studentGuardians = $Student->getValue('Guardians');
#                if (is_array($studentGuardians) && !empty($studentGuardians)) {
#                    $studentCanvasId = static::_getCanvasUserID($Student->ID);
#                    foreach ($studentGuardians as $Guardian) {
#                        $slateEnrollments['observers'][] = $Guardian->Username;
#
#                        $guardianMappingData = [
#                            'ContextClass' => $Guardian->getRootClass(),
#                            'ContextID' => $Guardian->ID,
#                            'Connector' => static::getConnectorId(),
#                            'ExternalKey' => 'user[id]'
#                        ];
#
#                        // only enroll guardians that have logged into slate
#                        if ($GuardianMapping = Mapping::getByWhere($guardianMappingData)) {
#                            $enrollGuardian = !array_key_exists($Guardian->Username, $canvasEnrollments['observers']);
#
#                            if ($enrollGuardian) {
#                                if (!$pretend) {
#                                    $observerPushResults = static::_logSyncResults(
#                                        $Job,
#                                        $results,
#                                        static::createSectionEnrollment(
#                                            $Guardian,
#                                            $CourseMapping,
#                                            $SectionMapping,
#                                            [
#                                                'type' => 'observer',
#                                                'observeeId' => $studentCanvasId
#                                            ]
#                                        )
#                                    );
#                                } else {
#                                    $Job->log("Creating observer enrollment for $Guardian->Username observing $Student->Username in course section $Section->Code", LogLevel::NOTICE);
#                                }
#                            }
#                        }
#                    }
#                }
            }
            // remove observer enrollments
#            foreach (array_diff(array_keys($canvasEnrollments['observers']), $slateEnrollments['observers']) AS $observerUsername) {
#                $enrollmentId = $canvasEnrollments['observers'][$observerUsername]['id'];
#                if ($Observer = User::getByUsername($observerUsername)) { // todo: handle accounts deleted in slate?
#                    if (!$pretend) {
#                        $results = static::_logSyncResults(
#                            $Job,
#                            $results,
#                            static::removeSectionEnrollment(
#                                $Observer,
#                                $CourseMapping,
#                                $SectionMapping,
#                                [
#                                    'type' => 'observer',
#                                    'enrollmentId' => $enrollmentId,
#                                    'enrollmentTask' => 'delete'
#                                ]
#                            )
#                        );
#                    } else {
#                        $Job->log("Removing observer enrollment for $Observer->Username from course section $Section->Code", LogLevel::NOTICE);
#                    }
#                }
#            }

            // remove students from canvas
            foreach (array_diff(array_keys($canvasEnrollments['students']), $slateEnrollments['students']) AS $studentUsername) {
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
                        $Job->log(
                            LogLevel::NOTICE,
                            'Removing {enrollmentType} enrollment for {slateUsername} from course section {sectionCode}',
                            [
                                'sectionCode' => $Section->Code,
                                'slateUsername' => $Student->Username,
                                'enrollmentType' => 'student'
                            ]
                        );
                    }
                }
            }
        }

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
    protected static function createSectionEnrollment(IPerson $User, Mapping $SectionMapping, LoggerInterface $logger, $enrollmentType, $observeeId = null)
    {
        switch ($enrollmentType) {
            case 'student':
            case 'teacher':
            case 'observer':
                break;

            default:
                throw new \Exception("Enrollment type invalid. ($type)");
        }

        $canvasUserId = static::_getCanvasUserID($User->ID);

        try {
            $enrollmentData = [
                'enrollment[user_id]' => $canvasUserId,
                'enrollment[type]' => ucfirst($enrollmentType).'Enrollment',
                'enrollment[enrollment_state]' => 'active',
                'enrollment[notify]' => 'false',
            ];

            if ($enrollmentType == 'observer' && !empty($observeeId)) {
                $enrollmentData['enrollment[associated_user_id]'] = $observeeId;
            }

            $canvasResponse = CanvasAPI::createEnrollmentsForSection(
                $SectionMapping->ExternalIdentifier,
                $enrollmentData
            );

            $logger->log(
                LogLevel::DEBUG,
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
        $validEnrollmentTypes = [
            'student',
            'teacher',
            'observer'
        ];
        switch ($enrollmentType) {
            case 'student':
            case 'teacher':
            case 'observer':
                break;

            default:
                throw new \Exception("Enrollment type invalid. ($type)");
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

            $logger->log(
                LogLevel::DEBUG,
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
    protected static function getDefaultLogger()
    {
        return static::$defaultLogger ?: \Emergence\Logger::getLogger();
    }

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