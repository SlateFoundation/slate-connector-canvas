<?php

namespace RemoteSystems;

use RuntimeException;

class Canvas
{
    public static $canvasHost;
    public static $apiToken;
    public static $accountID;


    protected static $logger;
    public static function setLogger(\Psr\Log\LoggerInterface $logger)
    {
        static::$logger = $logger;
    }


    public static function executeRequest($path, $requestMethod = 'GET', $params = [])
    {
        $url = 'https://'.static::$canvasHost.'/api/v1/'.$path;


        // confugre cURL
        static $ch;

        if (!$ch) {
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                sprintf('Authorization: Bearer %s', static::$apiToken)
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
        }


        // (re)configure cURL for this request
        curl_setopt($ch, CURLOPT_POST, $requestMethod == 'POST');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $requestMethod);

        if ($requestMethod == 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);

            // strip numeric indexes in array keys
            $url .= '?'.(is_string($params) ? $params : preg_replace('/(%5B)\d+(%5D=)/i', '$1$2', http_build_query($params)));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        }

        curl_setopt($ch, CURLOPT_URL, $url);

        if (static::$logger) {
            static::$logger->debug("$requestMethod\t" . str_replace('?', "\t?", $url));
        }


        // fetch pages
        $responseData = [];
        do {
            $response = curl_exec($ch);
            $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            $responseHeadersSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $responseHeaders = substr($response, 0, $responseHeadersSize);
            $responseData = array_merge($responseData, json_decode(substr($response, $responseHeadersSize), true));

            if ($responseCode >= 400 || $responseCode < 200) {
                throw new \RuntimeException(
                    (
                        !empty($responseData['errors'])
                        ? "Canvas reports: {$responseData['errors'][0]['message']}"
                        : "Canvas request failed with code {$responseCode}"
                    ),
                    $responseCode
                );
            }

            if (
                !empty($params['per_page'])
                && preg_match('/^link:\s+(.+?)\r\n/mi', $responseHeaders, $responseHeaderMatches)
                && !empty($responseHeaderMatches[1])
            ) {
                $responseHeaderLinks = preg_split('/\s*,\s*/', $responseHeaderMatches[1]);

                foreach ($responseHeaderLinks as $linkLine) {
                    $linkSegments = preg_split('/\s*;\s*/', $linkLine);
                    $linkUrl = substr(array_shift($linkSegments), 1, -1);

                    foreach ($linkSegments as $linkSegment) {
                        if (preg_match('/rel=([\'"]?)next\1/i', $linkSegment)) {
                            curl_setopt($ch, CURLOPT_URL, $linkUrl);
                            // continue to top-most do-loop to load new URL
                            continue 3;
                        }
                    }
                }

                // no link header or next link found
                break;
            } else {
                // no paging, finish after first request
                break;
            }

        } while (true);


        return $responseData;
    }

    /**
     * Format a timestamp to a format that will string-match what Canvas returns
     */
    public static function formatTimestamp($timestamp)
    {
        return $timestamp ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : null;
    }


    // Accounts: https://canvas.instructure.com/doc/api/accounts.html
    public static function getAccount($accountID = null)
    {
        if (!$accountID) {
            $accountID = static::$accountID;
        }

        return static::executeRequest("accounts/$accountID");
    }


    // Enrollment Terms: https://canvas.instructure.com/doc/api/enrollment_terms.html
    public static function getTerms($accountID = null)
    {
        if (!$accountID) {
            $accountID = static::$accountID;
        }

        return static::executeRequest("accounts/$accountID/terms");
    }


    // Users: https://canvas.instructure.com/doc/api/users.html
    public static function getUser($userID)
    {
        return static::executeRequest("users/$userID/profile");
    }

    public static function createUser($data, $accountID = null)
    {
        if (!$accountID) {
            $accountID = static::$accountID;
        }

        return static::executeRequest("accounts/$accountID/users", 'POST', $data);
    }

    public static function updateUser($userID, $data)
    {
        return static::executeRequest("users/$userID", 'PUT', $data);
    }


    // Logins: https://canvas.instructure.com/doc/api/logins.html
    public static function getLoginsByUser($userID)
    {
        return static::executeRequest("users/$userID/logins");
    }

    public static function updateLogin($loginID, $data, $accountID = null)
    {
        if (!$accountID) {
            $accountID = static::$accountID;
        }

        return static::executeRequest("accounts/$accountID/logins/$loginID", 'PUT', $data);
    }


    // Courses: https://canvas.instructure.com/doc/api/courses.html
    public static function getCourse($courseID, array $include = [])
    {
        return static::executeRequest("courses/$courseID", 'GET', implode('&', array_map(function ($value) {
            return 'include[]='.$value;
        }, $include)));
    }

    public static function createCourse($data, $accountID = null)
    {
        if (!$accountID) {
            $accountID = static::$accountID;
        }

        return static::executeRequest("accounts/$accountID/courses", 'POST', $data);
    }

    public static function updateCourse($courseID, $data)
    {
        return static::executeRequest("courses/$courseID", 'PUT', $data);
    }

    public static function deleteCourse($courseID, $event = 'conclude')
    {
        return static::executeRequest("courses/$courseID", 'DELETE', [
            'event' => $event
        ]);
    }


    // Sections: https://canvas.instructure.com/doc/api/sections.html
    public static function getSection($sectionID)
    {
        return static::executeRequest("sections/$sectionID");
    }

    public static function getSectionsByCourse($courseID)
    {
        return static::executeRequest("courses/$courseID/sections", 'GET', ['per_page' => 1000]);
    }

    public static function createSection($courseID, $data)
    {
        return static::executeRequest("courses/$courseID/sections", 'POST', $data);
    }

    public static function updateSection($sectionID, $data)
    {
        return static::executeRequest("sections/$sectionID", 'PUT', $data);
    }

    public static function deleteSection($sectionID)
    {
        return static::executeRequest("sections/$sectionID", 'DELETE');
    }


    // Enrollments: https://canvas.instructure.com/doc/api/enrollments.html
    public static function getEnrollmentsBySection($sectionID)
    {
        return static::executeRequest("sections/$sectionID/enrollments", 'GET', ['per_page' => 1000]);
    }

    public static function getEnrollmentsByUser($userId, array $options = [])
    {
        $options = array_merge([
            'state' => ['active', 'invited', 'creation_pending', 'deleted', 'rejected', 'completed', 'inactive'],
            'type' => ['StudentEnrollment', 'TeacherEnrollment', 'ObserverEnrollment', 'TaEnrollment'],
            'per_page' => 1000
        ], $options);

        return static::executeRequest("users/$userId/enrollments", 'GET', $options);
    }

    public static function createEnrollmentsForSection($sectionID, $data)
    {
        return static::executeRequest("sections/$sectionID/enrollments", 'POST', $data);
    }

    public static function deleteEnrollmentsForCourse($courseID, $enrollmentID, $task = 'conclude')
    {
        return static::executeRequest("courses/$courseID/enrollments/$enrollmentID", 'DELETE', [
            'task' => $task
        ]);
    }
}