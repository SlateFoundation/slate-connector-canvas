<?php

namespace Slate\Connectors\Canvas\Commands;

use Emergence\KeyedDiff;

class UpdateEnrollment implements ICommand
{
    private $userId;
    private $sectionId;
    private $newValues;
    private $oldValues;

    public function __construct($userId, $sectionId, array $newValues, array $oldValues = null)
    {
        $this->userId = $userId;
        $this->sectionId = $sectionId;
        $this->newValues = $newValues;
        $this->oldValues = $oldValues;
    }

    public function describe()
    {
        return [
            'SYNC {userId} IN {sectionId} CHANGING {changes}',
            [
                'userId' => $this->userId,
                'sectionId' => $this->sectionId,
                'changes' => new KeyedDiff($this->newValues, $this->oldValues),
            ],
        ];
    }

    public function execute()
    {
        dump(['execute' => $this]);
    }
}
