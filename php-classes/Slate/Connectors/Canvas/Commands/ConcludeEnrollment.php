<?php

namespace Slate\Connectors\Canvas\Commands;

use Emergence\Connectors\ICommand;
use Slate\Connectors\Canvas\API;

class ConcludeEnrollment implements ICommand
{
    private $enrollment;

    public function __construct(array $enrollment)
    {
        $this->enrollment = $enrollment;
    }

    public function describe()
    {
        return [
            'CONCLUDE {role} {userId} IN {sectionId}',
            [
                'userId' => "sis_user_id:{$this->enrollment['sis_user_id']}",
                'sectionId' => "sis_section_id:{$this->enrollment['sis_section_id']}",
                'role' => $this->enrollment['role'],
            ],
        ];
    }

    public function buildRequest()
    {
        return API::buildRequest(
            'DELETE',
            "courses/{$this->enrollment['course_id']}/enrollments/{$this->enrollment['id']}",
            [
                'task' => 'conclude',
            ]
        );
    }
}
