<?php

namespace Slate\Connectors\Canvas;

use Psr\Log\LogLevel;

use RemoteSystems\Canvas AS CanvasAPI;

use Emergence\Connectors\Job;
use Emergence\Connectors\Mapping;
use Emergence\People\IPerson;
use Emergence\People\Person;
use Emergence\People\User;
use Emergence\Util\Data AS DataUtil;
use Emergence\Connectors\SAML2;

use Slate\Term;
use Slate\Courses\Section;
use Slate\Courses\SectionParticipant;
use Slate\People\Student;


class Connector extends \Emergence\Connectors\AbstractConnector implements \Emergence\Connectors\ISynchronize
{
    use \Emergence\Connectors\IdentityConsumerTrait;
    
    public static $title = 'Canvas';
    public static $connectorId = 'canvas';

    public static $defaultLogger;
    /**
     * IdentityConsumer interface methods
     */
    
    public static function handleLoginRequest(IPerson $Person)
    {
        return SAML2::handleLoginRequest($Person);
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
        $Mapping = Mapping::getByWhere(array(
            'ContextClass' => $Person->getRootClass(),
            'ContextID' => $Person->ID,
            'Connector' => static::getConnectorId(),
            'ExternalKey' => 'user[id]'
        ));


        try {
            $userSyncResult = static::pushUser($Person, false);
            $sectionSyncResult = static::pushEnrollments($Person);
        } catch (SyncException $exception) {
            \MICS::dump($exception, 'beforeAuthentication exception');
            return false;
        }
        
        if (empty($results['existing']) && empty($results['created'])) {
            return false;
        }
        if (!$Mapping) {
        }
        
        if (is_callable(static::$beforeAuthenticate)) {
            if(false === call_user_func(static::$beforeAuthenticate, $Person)) {
                return false;
            }
        }
        
        return true;
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
        // initialize results
        $results = [];
        
        $conditions = [
            'ID' => [
                'values' => [1, 5, 77, 79]    
            ]   
        ];

#        $conditions = [
#            'AccountLevel IS NOT NULL AND ( (AccountLevel NOT IN ("Disabled", "Contact", "User", "Staff")) OR (Class = "Slate\\\\People\\\\Student" AND AccountLevel != "Disabled") )', // no disabled accounts, non-users, guests, and non-teaching staff
#            'GraduationYear IS NULL OR GraduationYear >= ' . Term::getClosestGraduationYear(), // no alumni
#            'Username IS NOT NULL' // username must be assigned
#        ];
        
        $results = [
            'analyzed' => 0,
            'existing' => 0,
            'skipped' => 0,
            'failed' => 0,
            'logins' => [
                'updated' => 0
            ]
#            'enrollments' => [
#                'created' => 0,
#                'students' => 0
#            ]
        ];

        foreach (User::getAllByWhere($conditions) AS $User) {
            $Job->log("\nAnalyzing Slate user {$User->Username} ({$User->Class}/{$User->GraduationYear})", LogLevel::DEBUG);
            $results['analyzed']++;
            try {
                $syncResult = static::pushUser($User, $pretend);
                if ($syncResult->getStatus() === SyncResult::STATUS_CREATED) {
                    $results['created']++;
                } else if ($syncResult->getStatus() === SyncResult::STATUS_UPDATED) {
                    $results['updated']++;
                    if ($syncResult->getContext('updateContext') == 'login') {
                        $results['logins']['updated']++;
                    }
                } else if ($syncResult->getStatus() === SyncResult::STATUS_SKIPPED) {
                    continue;
                }

                $enrollmentResult = static::pushEnrollments($User, $pretend);
                // todo: handle sync results that can contain "multiple" statuses
            } catch (SyncException $e) {
                $results['failed']++;
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

    public static function pushUser(User $User, $pretend = true, $logger = null)
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
            $logger->log(LogLevel::DEBUG, "Found mapping to Canvas user {canvasUserId}, checking for updates...", [
                'canvasUserMapping' => $Mapping,
                'canvasUserId' => $Mapping->ExternalIdentifier
            ]);

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
                    //$Job->log('<blockquote>Canvas update user response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                }
                $logger->log(LogLevel::DEBUG, "Updated user {slateUsername}", ['slateUsername' => $User->Username]);
            } else {
                $logger->log(LogLevel::DEBUG, "Canvas user matches Slate user");
            }

            // sync login
            if (!empty($canvasLoginChanges)) {
                $changes['login'] = $canvasLoginChanges;
                if (!$pretend) {
                    $canvasResponse = CanvasAPI::updateLogin($logins[0]['id'], DataUtil::extractToFromDelta($canvasLoginChanges));
                    //$Job->log('<blockquote>Canvas update login response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                }
                // get existing login ID
                $logins = CanvasAPI::getLoginsByUser($Mapping->ExternalIdentifier);
                //$Job->log('<blockquote>Canvas logins response: ' . var_export($logins, true) . "</blockquote>\n");

                if (empty($logins)) {
                    return new SyncException('Unexpected: No existing logins found for canvas user: {canvasUserId}', [
                        'canvasUserId' => $Mapping->ExternalIdentifier,
                        'response' => $canvasResponse,
                        'changes' => $changes
                    ]);
                }

                $logger->log(LogLevel::DEBUG, 'Updated login for user {slateUsername}', ['slateUsername' => $User->Username]);
            } else {
                $logger->log(LogLevel::DEBUG, 'Canvas login matches Slate login', []);
            }
        } else {

            // create user if no mapping found
            if (!$User->Email) {
                return new SyncResult([
                    'status' => SyncResult::STATUS_SKIPPED,
                    
                    'message' => 'No email, skipping {slateUsername}',
                    'context' => [
                        'slateUsername' => $User->Username,
                        ''
                    ]
                ]);
            }

            if ($pretend) {
                $logger->log(LogLevel::NOTICE, 'Created canvas user for {slateUsername}', ['slateUsername' => $User->Username]);
            } else {
                $canvasResponse = CanvasAPI::createUser([
                    'user[name]' => $User->FullName,
                    'user[short_name]' => $User->FirstName,
                    'pseudonym[unique_id]' => $User->Email,
                    'pseudonym[sis_user_id]' => $User->Username,
                    'communication_channel[type]' => 'email',
                    'communication_channel[address]' => $User->Email
                ]);
                //$Job->log('<blockquote>Canvas create response: ' . var_export($canvasResponse, true) . "</blockquote>\n");

                if (!empty($canvasResponse['id'])) {
                    $mappingData['ExternalIdentifier'] = $canvasResponse['id'];
                    Mapping::create($mappingData, true);

                    $results->log(LogLevel::NOTICE, 'Created canvas user for {slateUsername}, saved mapping to new canvas user #{canvasUserId}', [
                        'slateUsername' => $User->Username,
                        'canvasUserId' => $canvasResponse['id']
                    ]);
                } else {
                    return new SyncException('Failed to create canvas user for {slateUsername}', [
                        'slateUsername' => $User->Username,
                        'response' => $canvasResponse
                    ]);
                }
            }
        }
        
        return $results;
    }
    
    /*
    * Push Slate User course enrollments to Canvas API.
    * @param $User User object
    * @param $pretend boolean
    * @return SyncResult object?
    */
    
    public static function pushEnrollments(User $User, $pretend = true, $logger = null)
    {
        if (!$logger) {
            $logger = static::getDefaultLogger();
        }
        
        $studentEnrollments = SectionParticipant::getAllByWhere([
            'PersonID' => $User->ID
        ]);
        
        $canvasEnrollments = [];
        foreach (CanvasAPI::getEnrollmentsByUser(static::_getCanvasUserId($User->ID)) as $canvasUserEnrollment) {
            $canvasEnrollments[$canvasUserEnrollment['course_section_id']] = $canvasUserEnrollment;
        }
        
        //sync student enrollments
        foreach ($studentEnrollments as $studentEnrollment) {
            if ($studentEnrollment->Section->Status == 'Live' && $studentEnrollment->Section->Term->Status == 'Live') {
                if (
                    $CourseMapping = Mapping::getByWhere([
                        'ContextClass' => $studentEnrollment->Section->getRootClass(),
                        'ContextID' => $studentEnrollment->Section->ID,
                        'Connector' => static::getConnectorId(),
                        'ExternalKey' => 'course[id]'
                    ]) &&
                    $SectionMapping = Mapping::getByWhere([
                        'ContextClass' => $studentEnrollment->Section->getRootClass(),
                        'ContextID' => $studentEnrollment->Section->ID,
                        'Connector' => static::getConnectorId(),
                        'ExternalKey' => 'course_section[id]'
                    ])
                ) { // sync enrollments for already synced sections
                    switch ($studentEnrollment->Role) {
                        case 'Student':
                            $studentEnrollmentType = 'student';
                            break;
                        case 'Teacher':
                        case 'Assistant':
                            $studentEnrollmentType = 'teacher';
                            break;
                        case 'Observer':
                            $studentEnrollmentType = 'observer';
                            break;
                    }

                    $enrollmentIndex = array_key_exists($SectionMapping->ExternalIdentifier, $canvasEnrollments);
                    if (!$enrollmentIndex) { //create section enrollment
                        try {
                            $canvasEnrollment = static::createSectionEnrollment($User, $CourseMapping, $SectionMapping, [
                                'type' => $studentEnrollmentType
                            ]);
                        } catch (SyncException $e) {
                            $failed[$SectionMapping->ExternalIdentifier] = $studentEnrollment->Section;
                            continue;
                            // todo: handle failed enrollments    
                        }
                    } // todo: handle changes to enrollment type.
                }
            }
        }
        
        // todo: remove enrollments in canvas that are not in slate?
        
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
            
            $studentCanvasId = static::_getCanvasUserID($StudentMapping->ExternalIdentifier);
            foreach ($Ward->Sections as $Section) {
                $CourseMapping = Mapping::getByWhere([
                    'ContextClass' => $Section->getRootClass(),
                    'ContextID' => $Section->ID,
                    'Connector' => static::getConnectorId(),
                    'ExternalKey' => 'course[id]'
                ]);
                $SectionMapping = Mapping::getByWhere([
                    'ContextClass' => $Section->getRootClass(),
                    'ContextID' => $Section->ID,
                    'Connector' => static::getConnectorId(),
                    'ExternalKey' => 'course_section[id]'
                ]);
                
                if (!$CourseMapping || !$SectionMapping) {
                    continue;
                }
                
                if (!$pretend) {
                    try {
                        $observerPushResults[] = static::createSectionEnrollment(
                            $User,
                            $CourseMapping,
                            $SectionMapping,
                            [
                                'type' => 'observer',
                                'observeeId' => $studentCanvasId
                            ]
                        );
#                        $observerPushResults[] = $observerPushResult;
                    } catch (SyncException $e) {
                        $failed++;
                    } // todo: handle failed observer enrollments

                    // todo: better compile sub-sync results?
                } else {
                    $logger->log("Creating observer enrollment for {slateUsername} observing {observeeSlateUsername} in course section {sectionCode}", ['sectionCode' => $Section->Code, 'slateUsername' => $User->Username, 'observeeSlateUsername' => $Ward->Username], LogLevel::NOTICE);
                }
            }
        }

        
    }

    public static function pushSections(Job $Job, $pretend = true)
    {
        // initialize results
        $results = [];

#        $sectionConditions[] = 'TermID IN ('.implode(',', Term::getClosest()->getMaster()->getContainedTermIDs()).')';
        
        $sectionConditions = [
            'ID' => 16
        ];
        
        foreach (Section::getAllByWhere($sectionConditions) AS $Section) {
            $results['sections']['analyzed']++;
            $canvasSection = null;

            // build section title
            // TODO: use configurable formatter
            $sectionTitle = $Section->Title;

            if ($Section->Schedule) {
                $sectionTitle .= ' (' . $Section->Schedule->Title . ')';
            }

            $Job->log("\nAnalyzing Slate section $sectionTitle", LogLevel::DEBUG);

            if (!count($Section->Students)) {
                $Job->log('Section has no students, skipping.', LogLevel::INFO);
                $results['sections']['skippedEmpty']++;
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
                    $Job->log("Created canvas course for $sectionTitle", LogLevel::NOTICE);
                    $results['sections']['coursesCreated']++;
                } else {
                    $canvasResponse = CanvasAPI::createCourse([
                        'account_id' => CanvasAPI::$accountID,
                        'course[name]' => $sectionTitle,
                        'course[course_code]' => $Section->Code,
                        'course[start_at]' => $Section->Term->StartDate,
                        'course[end_at]' => $Section->Term->EndDate,
                        'course[sis_course_id]' => $Section->Code
                    ]);
                    //$Job->log('<blockquote>Canvas create response: ' . var_export($canvasResponse, true) . "</blockquote>\n");

                    if (!empty($canvasResponse['id'])) {
                        $courseMappingData['ExternalIdentifier'] = $canvasResponse['id'];
                        $CourseMapping = Mapping::create($courseMappingData, true);

                        $Job->log("Created canvas section for course $sectionTitle, saved mapping to new canvas course #{$canvasResponse[id]}", LogLevel::NOTICE);
                        $results['sections']['coursesCreated']++;
                    } else {
                        $Job->log('Failed to create canvas course', LogLevel::ERROR);
                        $results['sections']['coursesCreateFailed']++;
                        continue;
                    }
                }

            } else {
                $results['sections']['coursesExisting']++;

                // update user if mapping exists
                $Job->log("Found mapping to Canvas course $CourseMapping->ExternalIdentifier, checking for updates...", LogLevel::DEBUG);

                $canvasCourse = CanvasAPI::getCourse($CourseMapping->ExternalIdentifier);

                //$Job->log('<blockquote>Canvas course response: ' . var_export($canvasCourse, true) . "</blockquote>\n");

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

                if (!empty($canvasTo)) {
                    if (!$pretend) {
                        $canvasResponse = CanvasAPI::updateCourse($CourseMapping->ExternalIdentifier, $canvasTo);
                        //$Job->log('<blockquote>Canvas update response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                    }

                    foreach ($canvasTo AS $field => $to) {
                        $Job->log("\t$field:\t{$canvasFrom[$field]}\t->\t$to", LogLevel::NOTICE);
                    }

                    $results['coursesUpdated']++;
                } else {
                    $Job->log('Canvas course matches Slate course.', LogLevel::DEBUG);
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
                    $Job->log("Created canvas section for $sectionTitle", LogLevel::NOTICE);
                    $results['sections']['sectionsCreated']++;
                } else {
                    $canvasResponse = CanvasAPI::createSection($CourseMapping->ExternalIdentifier, [
                        'course_section[name]' => $sectionTitle,
                        'course_section[start_at]' => $Section->Term->StartDate,
                        'course_section[end_at]' => $Section->Term->EndDate,
                        'course_section[sis_section_id]' => $Section->Code
                    ]);
                    //$Job->log('<blockquote>Canvas create response: ' . var_export($canvasResponse, true) . "</blockquote>\n");

                    if (!empty($canvasResponse['id'])) {
                        $sectionMappingData['ExternalIdentifier'] = $canvasResponse['id'];
                        $SectionMapping = Mapping::create($sectionMappingData, true);

                        $Job->log("Created canvas section for $sectionTitle, saved mapping to new canvas section #{$canvasResponse[id]}", LogLevel::NOTICE);
                        $results['sections']['sectionsCreated']++;
                    } else {
                        $Job->log('Failed to create canvas section', LogLevel::ERROR);
                        $results['sections']['sectionsCreateFailed']++;
                        continue;
                    }
                }
            } else {
                $results['sections']['sectionsExisting']++;

                // update user if mapping exists
                $Job->log("Found mapping to Canvas section $SectionMapping->ExternalIdentifier, checking for updates...", LogLevel::DEBUG);

                $canvasSection = CanvasAPI::getSection($SectionMapping->ExternalIdentifier);

                //$Job->log('<blockquote>Canvas section response: ' . var_export($canvasSection, true) . "</blockquote>\n");

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

                if (!empty($canvasTo)) {
                    if (!$pretend) {
                        $canvasResponse = CanvasAPI::updateSection($SectionMapping->ExternalIdentifier, $canvasTo);
                        //$Job->log('<blockquote>Canvas update response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                    }

                    $changes = [];
                    foreach ($canvasTo AS $field => $to) {
                        $Job->log("\t$field:\t{$canvasFrom[$field]}\t->\t$to", LogLevel::NOTICE);
                    }

                    $results['sections']['sectionsUpdated']++;
                } else {
                    $Job->log('Canvas section matches Slate section.', LogLevel::DEBUG);
                }
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
                // check if student needs enrollment
                $enrollTeacher = !array_key_exists($Teacher->Username, $canvasEnrollments['teachers']);
                if ($enrollTeacher) {
                    if (!$pretend) {
                        $results = static::_logSyncResults(
                            $Job,
                            $results,
                            static::createSectionEnrollment(
                                $Teacher,
                                $CourseMapping,
                                $SectionMapping,
                                [
                                    'type' => 'teacher'
                                ]
                            )
                        );
                    } else {
                        $Job->log("Enrolling teacher $Teacher->Username into course section $Section->Code", LogLevel::NOTICE);
                    }
                }
            }

            // remove teachers from canvas
            if (!empty($Job->Config['removeTeachers'])) {
                foreach (array_diff(array_keys($canvasEnrollments['teachers']), $slateEnrollments['teachers']) AS $teacherUsername) {
                    $enrollmentId = $canvasEnrollments['teachers'][$teacherUsername]['id'];
                    if ($Teacher = User::getByUsername($teacherUsername)) { // todo: handle accounts deleted in slate?
                        if (!$pretend) {
                            $results = static::_logSyncResults(
                                $Job,
                                $results,
                                static::removeSectionEnrollment(
                                    $Teacher,
                                    $CourseMapping,
                                    $SectionMapping,
                                    [
                                        'type' => 'teacher',
                                        'enrollmentId' => $enrollmentId
                                    ]
                                )
                            );
                        } else {
                            $Job->log("Removing teacher enrollment for $Teacher->Username from course section $Section->Code", LogLevel::NOTICE);
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
                        $results = static::_logSyncResults(
                            $Job,
                            $results,
                            static::createSectionEnrollment(
                                $Student,
                                $CourseMapping,
                                $SectionMapping,
                                [
                                    'type' => 'student'
                                ]
                            )
                        );
                    } else {
                        $Job->log("Enrolling student $Student->Username into course section $Section->Code", LogLevel::NOTICE);
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
                        $results = static::_logSyncResults(
                            $Job,
                            $results,
                            static::removeSectionEnrollment(
                                $Student,
                                $CourseMapping,
                                $SectionMapping,
                                [
                                    'type' => 'student',
                                    'enrollmentId' => $enrollmentId
                                ]
                            )
                        );
                    } else {
                        $Job->log("Removing enrollment for $Student->Username from course section $Section->Code", LogLevel::NOTICE);
                    }
                }
            }
        }

        unset($results['logs']);
        return $results;
    }
    
    /**
    * Create enrollment in canvas for user.
    * @param $User User object - User to enroll
    * @param $CourseMapping Mapping object - Mapping to canvas course to enroll user to
    * @param $SectionMapping Mapping object - Mapping to canvas course section to enroll user to
    * @param $settings array - Config array containing enrollment options
    * @return SyncResult object / SyncException?
    */
    public static function createSectionEnrollment(User $User, Mapping $CourseMapping, Mapping $SectionMapping, $settings = [])
    {
        $results = new SyncResult([
            'method' => 'createSectionEnrollment'
        ]);
        
        switch ($type = $settings['type']) {
            case 'student':
            case 'teacher':
            case 'observer':
                $enrollmentType = ucfirst($type).'Enrollment';
                break;
            
            default:
                throw new \Exception("Enrollment type invalid. ($type)");
        }
        
        $canvasUserId = static::_getCanvasUserID($User->ID);
        
        try {
            $enrollmentData = [
                'enrollment[user_id]' => $canvasUserId,
                'enrollment[type]' => $enrollmentType,
                'enrollment[enrollment_state]' => 'active',
                'enrollment[notify]' => 'false',
            ];

            if ($type == 'observer' && !empty($settings['observeeId'])) {
                $enrollmentData['enrollment[associated_user_id]'] = $settings['observeeId'];
            }
            
            $canvasResponse = CanvasAPI::createEnrollmentsForSection(
                $SectionMapping->ExternalIdentifier,
                $enrollmentData    
            );
            
        } catch (\Exception $e) {
            return new SyncException("Failed to map {enrollmentType} {slateUsername} to Canvas user", ['enrollmentType' => $type, 'slateUsername' => $User->Username, 'exception' => $e]);
#            $results->log("Failed to map $type $User->Username to Canvas user", LogLevel::ERROR);
#            return $results;
        }
        
        $results->log("Enrolled {enrollmentType} {slateUsername}", ['slateUsername' => $User->Username, 'enrollmentType' => $type], LogLevel::NOTICE);
        
        return $results;
    }
    
    /**
    * Remove enrollment in canvas for user.
    * @param $User User object - User to unenroll
    * @param $CourseMapping Mapping object - Mapping to canvas course to unenroll user to
    * @param $SectionMapping Mapping object - Mapping to canvas course section to unenroll user to
    * @param $settings array - Config array containing unenrollment options
    * @return SyncResult object / SyncException?
    */
    public static function removeSectionEnrollment(User $User, Mapping $CourseMapping, Mapping $SectionMapping, $settings = [])
    {
        $results = new SyncResult([
            'method' => 'removeSectionEnrollment'
        ]);
        
        switch ($type = $settings['type']) {
            case 'student':
            case 'teacher':
            case 'observer':
                $enrollmentType = ucfirst($type).'Enrollment';
                break;
            
            default:
                throw new \Exception("Enrollment type invalid. ($type)");
        }
        
        if (!isset($settings['enrollmentId'])) {
            throw new \Exception('Enrollment ID must be supplied.');
        }
        
        $canvasUserId = static::_getCanvasUserID($User->ID);
        $canvasSection = CanvasAPI::getSection($SectionMapping->ExternalIdentifier);
        
        $canvasResponse = CanvasAPI::deleteEnrollmentsForCourse(
            $canvasSection ? $canvasSection['course_id'] : $CourseMapping->ExternalIdentifier,
            $settings['enrollmentId'],
            $settings['enrollmentTask'] ?: null
        );
        
        $results->log("Deleting {enrollmentType} enrollment for {slateUsername} ({sectionCode})", ['sectionCode' => $SectionMappingh->Context->Code, 'slateUsername' => $User->Username, 'enrollmentType' => $type], LogLevel::NOTICE);

        return $results;
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