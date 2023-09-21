<?php

namespace Slate\Connectors\Canvas\Repositories;

use Psr\Log\LoggerInterface;
use Slate\Connectors\Canvas\API;

/**
 * API Reference: https://canvas.instructure.com/doc/api/enrollments.html.
 */
class Enrollments
{
    public static $validStates = [
        'active',
        'invited',
        'creation_pending',
        // 'deleted',
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
        $enrollments = API::buildAndExecuteRequest(
            'GET',
            "sections/{$sectionId}/enrollments",
            array_merge([
                'state' => array_diff(static::$validStates, ['deleted']),
                'type' => static::$validTypes,
                'per_page' => 1000,
            ], $params)
        );

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
        $enrollments = API::buildAndExecuteRequest(
            'GET',
            "users/{$userId}/enrollments",
            array_merge([
                'state' => array_diff(static::$validStates, ['deleted']),
                'type' => static::$validTypes,
                'per_page' => 1000,
            ], $params)
        );

        // load all embeded users into repository cache
        if ($this->usersRepository) {
            foreach ($enrollments as $enrollment) {
                $this->usersRepository->loadIntoCache($enrollment['user']);
            }
        }

        return $enrollments;
    }
}
