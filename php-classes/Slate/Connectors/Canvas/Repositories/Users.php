<?php

namespace Slate\Connectors\Canvas\Repositories;

use Psr\Log\LoggerInterface;
use RemoteSystems\Canvas;

class Users
{
    private $cache;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getLogger()
    {
        return $this->logger;
    }

    public function getById($userId, array $params = [])
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $user = Canvas::executeRequest("users/{$userId}", 'GET', $params);

        $this->loadIntoCache($user);

        return $user;
    }

    public function loadIntoCache(array $user)
    {
        if (isset($this->cache[$user['id']])) {
            $user = array_merge($this->cache[$user['id']], $user);
        }

        $this->cache[$user['id']] = $user;
        $this->cache["sis_user_id:{$user['sis_user_id']}"] = &$this->cache[$user['id']];
    }
}
