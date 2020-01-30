<?php

namespace Slate\Connectors\Canvas\Commands;

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
            'ACTIVATE {role} {userId} IN {sectionId} WITH {values} TRUE = {true} AND FALSE = {false} AND NULL = {null]',
            [
                'role' => $this->role,
                'userId' => $this->userId,
                'sectionId' => $this->sectionId,
                'values' => $this->values,
                'true' => true, 'false' => false, 'null' => null,
            ],
        ];
    }

    public function execute()
    {
        dump(['execute' => $this]);
    }
}
