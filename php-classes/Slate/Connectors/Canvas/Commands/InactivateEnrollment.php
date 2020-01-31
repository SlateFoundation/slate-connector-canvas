<?php

namespace Slate\Connectors\Canvas\Commands;

use Emergence\Connectors\ICommand;

class InactivateEnrollment implements ICommand
{
    private $userId;
    private $sectionId;
    private $role;

    public function __construct($userId, $sectionId, $role)
    {
        $this->userId = $userId;
        $this->sectionId = $sectionId;
        $this->role = $role;
    }

    public function describe()
    {
        return [
            'INACTIVATE {role} {userId} IN {sectionId}',
            [
                'userId' => $this->userId,
                'sectionId' => $this->sectionId,
                'role' => $this->role,
            ],
        ];
    }

    public function buildRequest()
    {
        dump(['buildRequest' => $this]);
    }
}
