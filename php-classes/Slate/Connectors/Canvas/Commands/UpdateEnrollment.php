<?php

namespace Slate\Connectors\Canvas\Commands;

use Emergence\Connectors\ICommand;
use Emergence\KeyedDiff;

class UpdateEnrollment implements ICommand
{
    private $userId;
    private $sectionId;
    private $role;
    private $newValues;
    private $oldValues;

    public function __construct($userId, $sectionId, $role, array $newValues, array $oldValues = null)
    {
        $this->userId = $userId;
        $this->sectionId = $sectionId;
        $this->role = $role;
        $this->newValues = $newValues;
        $this->oldValues = $oldValues;
    }

    public function describe()
    {
        return [
            'UPDATE {role} {userId} IN {sectionId} CHANGING {changes}',
            [
                'userId' => $this->userId,
                'sectionId' => $this->sectionId,
                'role' => $this->role,
                'changes' => new KeyedDiff($this->newValues, $this->oldValues),
            ],
        ];
    }

    public function buildRequest()
    {
        dump(['buildRequest' => $this]);
    }
}
