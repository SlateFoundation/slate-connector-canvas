<?php

namespace Slate\Connectors\Canvas\Commands;

use Emergence\Connectors\ICommand;

class ActivateEnrollment implements ICommand
{
    private $userId;
    private $sectionId;
    private $role;
    private $values;

    public function __construct($userId, $sectionId, $role, array $values = null)
    {
        $this->userId = $userId;
        $this->sectionId = $sectionId;
        $this->role = $role;
        $this->values = $values;
    }

    public function describe()
    {
        return [
            'ACTIVATE {role} {userId} IN {sectionId} WITH {values}',
            [
                'role' => $this->role,
                'userId' => $this->userId,
                'sectionId' => $this->sectionId,
                'values' => $this->values,
            ],
        ];
    }

    public function buildRequest()
    {
        dump(['buildRequest' => $this]);
    }
}
