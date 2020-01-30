<?php

namespace Slate\Connectors\Canvas\Repositories;

use Psr\Log\LoggerInterface;

class Sections
{
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getLogger()
    {
        return $this->logger;
    }
}
