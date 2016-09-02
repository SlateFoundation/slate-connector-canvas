<?php

namespace Slate\Connectors\Canvas;

use Psr\Log\LogLevel;

use RemoteSystems\Canvas AS CanvasAPI;
use Emergence\Connectors\Job;
use Emergence\Connectors\Mapping;
use Emergence\People\User;
use Emergence\Util\Data AS DataUtil;

use Slate\Term;
use Slate\Courses\Section;


class Connector extends \Emergence\Connectors\AbstractConnector implements \Emergence\Connectors\ISynchronize
{
    public static $title = 'Canvas';
    public static $connectorId = 'canvas';


    // workflow implementations
    protected static function _getJobConfig(array $requestData)
    {
        $config = parent::_getJobConfig($requestData);

        $config['pushUsers'] = !empty($requestData['pushUsers']);
        $config['pushSections'] = !empty($requestData['pushSections']);

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


        if (!$conditions) {
            $conditions = [
                'AccountLevel IS NOT NULL AND AccountLevel NOT IN ("Disabled", "Contact", "User", "Staff")', // no disabled accounts, non-users, guests, and non-teaching staff
                'GraduationYear IS NULL OR GraduationYear >= ' . Term::getClosestGraduationYear(), // no alumni
                'Username IS NOT NULL' // username must be assigned
            ];
        }


        foreach (User::getAllByWhere($conditions) AS $User) {
            $results['analyzed']++;

            $Job->log("\nAnalyzing Slate user {$User->Username} ({$User->Class}/{$User->GraduationYear})", LogLevel::DEBUG);

            // get mapping
            $mappingData = [
                'ContextClass' => $User->getRootClass(),
                'ContextID' => $User->ID,
                'Connector' => static::getConnectorId(),
                'ExternalKey' => 'user[id]'
            ];

            if ($Mapping = Mapping::getByWhere($mappingData)) {
                $results['existing']++;

                // update user if mapping exists
                $Job->log("Found mapping to Canvas user $Mapping->ExternalIdentifier, checking for updates...", LogLevel::DEBUG);

                $canvasUser = CanvasAPI::getUser($Mapping->ExternalIdentifier);
                //$Job->log('<blockquote>Canvas user response: ' . var_export($canvasUser, true) . "</blockquote>\n");


                // detect needed changes
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
                    // \Debug::dumpVar([
                    //     '$canvasUserChange' => $canvasUserChange,
                    //     'DataUtil::extractToFromDelta' => DataUtil::extractToFromDelta($canvasUserChange)
                    // ]);
                    if (!$pretend) {
                        $canvasResponse = CanvasAPI::updateUser($Mapping->ExternalIdentifier, $canvasTo['user']);
                        //$Job->log('<blockquote>Canvas update user response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                    }

                    $Job->log([
                        'action' => 'update',
                        'changes' => $canvasUserChanges,
                        'message' => "Updated user $User->Username"
                    ]);

                    $results['updated']++;
                } else {
                    $Job->log('Canvas user matches Slate user.', LogLevel::DEBUG);
                }


                // sync login
                if (!empty($canvasLoginChanges)) {
                    // \Debug::dumpVar([
                    //     '$canvasLoginChanges' => $canvasLoginChanges,
                    //     'DataUtil::extractToFromDelta' => DataUtil::extractToFromDelta($canvasLoginChanges)
                    // ]);
                    if (!$pretend) {
                        $canvasResponse = CanvasAPI::updateLogin($logins[0]['id'], $canvasTo['login']);
                        //$Job->log('<blockquote>Canvas update login response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                    }

                    // get existing login ID
                    $logins = CanvasAPI::getLoginsByUser($Mapping->ExternalIdentifier);
                    //$Job->log('<blockquote>Canvas logins response: ' . var_export($logins, true) . "</blockquote>\n");

                    if (empty($logins)) {
                        $Job->log('Unexpected: No existing logins found for canvas user', LogLevel::ERROR);
                        $results['logins']['loginNotFound']++;
                        continue;
                    }

                    $Job->log([
                        'action' => 'update',
                        'changes' => $canvasLoginChanges,
                        'message' => "Updated login for user $User->Username"
                    ]);

                    $results['logins']['updated']++;
                } else {
                    $Job->log('Canvas login matches Slate login.', LogLevel::DEBUG);
                }
            } else {

                // create user if no mapping found
                if (!$User->Email) {
                    $Job->log("No email on record, skipping $User->Username", LogLevel::ERROR);
                    $results['skipped']['noEmail']++;
                    continue;
                }

                if (!$User->PasswordClear) {
                    $Job->log("No password on record, skipping $User->Username", LogLevel::ERROR);
                    $results['skipped']['noPassword']++;
                    continue;
                }


                if ($pretend) {
                    $Job->log("Created canvas user for $User->Username", LogLevel::NOTICE);
                    $results['created']++;
                } else {
                    $canvasResponse = CanvasAPI::createUser([
                        'user[name]' => $User->FullName,
                        'user[short_name]' => $User->FirstName,
                        'pseudonym[unique_id]' => $User->Email,
                        'pseudonym[sis_user_id]' => $User->Username,
                        'pseudonym[password]' => $User->PasswordClear,
                        'communication_channel[type]' => 'email',
                        'communication_channel[address]' => $User->Email
                    ]);
                    //$Job->log('<blockquote>Canvas create response: ' . var_export($canvasResponse, true) . "</blockquote>\n");

                    if (!empty($canvasResponse['id'])) {
                        $mappingData['ExternalIdentifier'] = $canvasResponse['id'];
                        Mapping::create($mappingData, true);

                        $Job->log("Created canvas user for $User->Username, saved mapping to new canvas user #{$canvasResponse[id]}", LogLevel::NOTICE);
                        $results['created']++;
                    } else {
                        $Job->log('Failed to create canvas user', LogLevel::ERROR);
                        $results['createFailed']++;
                    }
                }
            }

        }


        return $results;
    }

    public static function pushSections(Job $Job, $pretend = true)
    {
        // initialize results
        $results = [];

        $sectionConditions[] = 'TermID IN ('.implode(',', Term::getClosest()->getMaster()->getContainedTermIDs()).')';

        foreach (Section::getAllByWhere($sectionConditions) AS $Section) {
            $results['sections']['analyzed']++;
            $canvasSection = null;

            $Job->log("\nAnalyzing Slate section $Section->Title", LogLevel::DEBUG);

            if (!count($Section->Students)) {
                $results['sections']['skippedEmpty']++;

                $Job->log('Section has no students, skipping.', LogLevel::INFO);
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
                    $Job->log("Created canvas course for $Section->Title", LogLevel::NOTICE);
                    $results['sections']['coursesCreated']++;
                } else {
                    $canvasResponse = CanvasAPI::createCourse([
                        'account_id' => CanvasAPI::$accountID,
                        'course[name]' => $Section->Title,
                        'course[course_code]' => $Section->Code,
                        'course[start_at]' => $Section->Term->StartDate,
                        'course[end_at]' => $Section->Term->EndDate,
                        'course[sis_course_id]' => $Section->Code
                    ]);
                    //$Job->log('<blockquote>Canvas create response: ' . var_export($canvasResponse, true) . "</blockquote>\n");

                    if (!empty($canvasResponse['id'])) {
                        $courseMappingData['ExternalIdentifier'] = $canvasResponse['id'];
                        $CourseMapping = Mapping::create($courseMappingData, true);

                        $Job->log("Created canvas section for course $Section->Title, saved mapping to new canvas course #{$canvasResponse[id]}", LogLevel::NOTICE);
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

                if ($canvasCourse['name'] != $Section->Title) {
                    $canvasFrom['course[name]'] = $canvasCourse['name'];
                    $canvasTo['course[name]'] = $Section->Title;
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
                    $Job->log("Created canvas section for $Section->Title", LogLevel::NOTICE);
                    $results['sections']['sectionsCreated']++;
                } else {
                    $canvasResponse = CanvasAPI::createSection($CourseMapping->ExternalIdentifier, [
                        'course_section[name]' => $Section->Title,
                        'course_section[start_at]' => $Section->Term->StartDate,
                        'course_section[end_at]' => $Section->Term->EndDate,
                        'course_section[sis_section_id]' => $Section->Code
                    ]);
                    //$Job->log('<blockquote>Canvas create response: ' . var_export($canvasResponse, true) . "</blockquote>\n");

                    if (!empty($canvasResponse['id'])) {
                        $sectionMappingData['ExternalIdentifier'] = $canvasResponse['id'];
                        $SectionMapping = Mapping::create($sectionMappingData, true);

                        $Job->log("Created canvas section for $Section->Title, saved mapping to new canvas section #{$canvasResponse[id]}", LogLevel::NOTICE);
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

                if ($canvasSection['name'] != $Section->Title) {
                    $canvasFrom['course_section[name]'] = $canvasSection['name'];
                    $canvasTo['course_section[name]'] = $Section->Title;
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
                'students' => []
            ];

            // get all enrollments, sort by type and index by username
            foreach (CanvasAPI::getEnrollmentsBySection($SectionMapping->ExternalIdentifier) AS $canvasEnrollment) {
                if ($canvasEnrollment['type'] == 'TeacherEnrollment') {
                    $canvasEnrollments['teachers'][$canvasEnrollment['user']['sis_user_id']] = $canvasEnrollment;
                } elseif ($canvasEnrollment['type'] == 'StudentEnrollment') {
                    $canvasEnrollments['students'][$canvasEnrollment['user']['sis_user_id']] = $canvasEnrollment;
                }
            }

            // add teachers to canvas
            foreach ($Section->Instructors AS $Instructor) {
                $results['teachersAnalyzed']++;
                $slateEnrollments['teachers'][] = $Instructor->Username;

                // check if teacher needs enrollment
                if (array_key_exists($Instructor->Username, $canvasEnrollments['teachers'])) {
                    continue;
                }

                if (!$pretend) {
                    $canvasResponse = CanvasAPI::createEnrollmentsForSection($SectionMapping->ExternalIdentifier, [
                        'enrollment[user_id]' => static::_getCanvasUserID($Instructor->ID),
                        'enrollment[type]' => 'TeacherEnrollment',
                        'enrollment[enrollment_state]' => 'active',
                        'enrollment[notify]' => 'false'
                    ]);
                    //$Job->log('<blockquote>Canvas create response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                }

                $Job->log("Enrolled teacher $Instructor->Username", LogLevel::NOTICE);
                $results['teachersAdded']++;
            }

            // remove teachers from canvas
            if (static::$removeTeachers) {
                foreach (array_diff(array_keys($canvasEnrollments['teachers']), $slateEnrollments['teachers']) AS $teacherUsername) {
                    if (!$pretend) {
                        $canvasResponse = CanvasAPI::deleteEnrollmentsForCourse(
                            $canvasSection ? $canvasSection['course_id'] : $CourseMapping->ExternalIdentifier, // the section may have been moved to another course in canvas
                            $canvasEnrollments['teachers'][$teacherUsername]['id'],
                            $enrollmentRemoveTask
                        );
                        //$Job->log('<blockquote>Canvas delete response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                    }

                    $Job->log("Removed teacher $teacherUsername", LogLevel::NOTICE);
                    $results['teachersRemoved']++;
                }
            }


            // add students to canvas
            foreach ($Section->Students AS $Student) {
                $results['studentsAnalyzed']++;

                // skip disabled students
                if ($Student->AccountLevel == 'Disabled') {
                    $Job->log("Ignored enrollments for disabled student $Student->Username", LogLevel::INFO);
                    $results['studentsSkipped']++;
                    continue;
                }


                $slateEnrollments['students'][] = $Student->Username;

                // check if student needs enrollment
                if (array_key_exists($Student->Username, $canvasEnrollments['students'])) {
                    continue;
                }


                if (!$pretend) {
                    $canvasResponse = CanvasAPI::createEnrollmentsForSection($SectionMapping->ExternalIdentifier, [
                        'enrollment[user_id]' => static::_getCanvasUserID($Student->ID),
                        'enrollment[type]' => 'StudentEnrollment',
                        'enrollment[enrollment_state]' => 'active',
                        'enrollment[notify]' => 'false',
                    ]);
                    //$Job->log('<blockquote>Canvas create response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                }

                $Job->log("Enrolled student $Student->Username", LogLevel::NOTICE);
                $results['studentsAdded']++;
            }

            // remove students from canvas
            foreach (array_diff(array_keys($canvasEnrollments['students']), $slateEnrollments['students']) AS $studentUsername) {
                if (!$pretend) {
                    $canvasResponse = CanvasAPI::deleteEnrollmentsForCourse(
                        $canvasSection ? $canvasSection['course_id'] : $CourseMapping->ExternalIdentifier, // the section may have been moved to another course in canvas
                        $canvasEnrollments['students'][$studentUsername]['id'],
                        $enrollmentRemoveTask
                    );
                    //$Job->log('<blockquote>Canvas delete response: ' . var_export($canvasResponse, true) . "</blockquote>\n");
                }

                $Job->log("Removed student $studentUsername", LogLevel::NOTICE);
                $results['studentsRemoved']++;
            }
        }


        return $results;
    }
}