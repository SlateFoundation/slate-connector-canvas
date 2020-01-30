<?php

namespace Slate\Connectors\Canvas\Repositories;

use Psr\Log\LoggerInterface;
use RemoteSystems\Canvas;

/**
 * API Reference: https://canvas.instructure.com/doc/api/enrollments.html.
 */
class Enrollments
{
    public static $activeStates = [
        'active',
        'invited',
        'creation_pending',
        'deleted',
        'rejected',
        'completed',
    ];

    public static $validStates = [
        'active',
        'invited',
        'creation_pending',
        'deleted',
        'rejected',
        'completed',
        'inactive',
    ];

    public static $validTypes = [
        'StudentEnrollment',
        'TeacherEnrollment',
        'ObserverEnrollment',
        'TaEnrollment',
    ];

    private $logger;
    private $usersRepository;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getLogger()
    {
        return $this->logger;
    }

    public function setUsersRepository(Users $usersRepository)
    {
        $this->usersRepository = $usersRepository;
    }

    public function getBySection($sectionId, array $params = [])
    {
        $params = array_merge([
            'state' => static::$activeStates,
            'type' => static::$validTypes,
            'per_page' => 1000,
        ], $params);

        $enrollments = Canvas::executeRequest("sections/{$sectionId}/enrollments", 'GET', $params);

        // load all embeded users into repository cache
        if ($this->usersRepository) {
            foreach ($enrollments as $enrollment) {
                $this->usersRepository->loadIntoCache($enrollment['user']);
            }
        }

        return $enrollments;
    }

    public function getByUser($userId, array $params = [])
    {
        $params = array_merge([
            'state' => static::$activeStates,
            'type' => static::$validTypes,
            'per_page' => 1000,
        ], $params);

        $enrollments = Canvas::executeRequest("users/{$userId}/enrollments", 'GET', $params);

        // load all embeded users into repository cache
        if ($this->usersRepository) {
            foreach ($enrollments as $enrollment) {
                $this->usersRepository->loadIntoCache($enrollment['user']);
            }
        }

        return $enrollments;
    }
}
