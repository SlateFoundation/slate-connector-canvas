<?php

namespace Slate\Connectors\Canvas\Commands;

use Emergence\Connectors\ICommand;
use Emergence\KeyedDiff;
use Slate\Connectors\Canvas\API;

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
        $params = [
            'enrollment[user_id]' => $this->userId,
            'enrollment[type]' => $this->role,
            'enrollment[enrollment_state]' => 'active',
            'enrollment[notify]' => 'false',
        ];

        foreach ($this->newValues as $enrollmentKey => $enrollmentValue) {
            $params["enrollment[{$enrollmentKey}]"] = $enrollmentValue;
        }

        return API::buildRequest('POST', "sections/{$this->sectionId}/enrollments", $params);
    }
}
