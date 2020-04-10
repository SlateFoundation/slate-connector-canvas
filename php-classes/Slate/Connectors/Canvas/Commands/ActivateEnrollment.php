<?php

namespace Slate\Connectors\Canvas\Commands;

use Emergence\Connectors\ICommand;
use Slate\Connectors\Canvas\API;

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
        $params = [
            'enrollment[user_id]' => $this->userId,
            'enrollment[type]' => $this->role,
            'enrollment[enrollment_state]' => 'active',
            'enrollment[notify]' => 'false',
        ];

        foreach ($this->values as $enrollmentKey => $enrollmentValue) {
            $params["enrollment[{$enrollmentKey}]"] = $enrollmentValue;
        }

        return API::buildRequest('POST', "sections/{$this->sectionId}/enrollments", $params);
    }
}
