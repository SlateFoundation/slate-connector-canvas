<?php

namespace Slate\Connectors\Canvas\Strategies;

use BadMethodCallException;
use Emergence\People\User;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;
use Slate\Connectors\Canvas\API;
use Slate\Connectors\Canvas\Commands\ActivateEnrollment;
use Slate\Connectors\Canvas\Commands\ConcludeEnrollment;
use Slate\Connectors\Canvas\Commands\InactivateEnrollment;
use Slate\Connectors\Canvas\Commands\UpdateEnrollment;
use Slate\Connectors\Canvas\Repositories\Enrollments as EnrollmentsRepository;
use Slate\Connectors\Canvas\Repositories\Users as UsersRepository;
use Slate\Courses\Section;
use Slate\Courses\SectionParticipant;

class PushEnrollments
{
    protected static $toCanvasRole = [
        'Student' => 'StudentEnrollment',
        'Teacher' => 'TeacherEnrollment',
        'Observer' => 'ObserverEnrollment',
        'Assistant' => 'TaEnrollment',
    ];

    protected static $toSlateRole = [
        'StudentEnrollment' => 'Student',
        'TeacherEnrollment' => 'Teacher',
        'ObserverEnrollment' => 'Observer',
        'TaEnrollment' => 'Assistant',
    ];

    private $logger;
    private $usersRepository;
    private $enrollmentsRepository;
    private $sis_section_id;
    private $sis_user_id;
    private $inactivateEnded;

    public function __construct(LoggerInterface $logger, array $options = [])
    {
        $this->logger = $logger;

        if (!empty($options['sis_section_id'])) {
            $this->sis_section_id = $options['sis_section_id'];
        }

        if (!empty($options['sis_user_id'])) {
            $this->sis_user_id = $options['sis_user_id'];
        }

        $this->inactivateEnded = !empty($options['inactivate_ended']);

        if (!$this->sis_section_id && !$this->sis_user_id) {
            throw new BadMethodCallException('sis_section_id and sis_user_id cannot both be omitted');
        }

        // initialize repositories
        $this->usersRepository = new UsersRepository($logger);
        $this->enrollmentsRepository = new EnrollmentsRepository($logger);
        $this->enrollmentsRepository->setUsersRepository($this->usersRepository);
    }

    /**
     * Analyze current state and yield list of operations.
     *
     * - Slate keys enrollments on section+person
     * - Canvas keys enrollments on section+person+role, so it can have many records for the same section+person
     */
    public function plan()
    {
        if (
            $this->sis_section_id
            && !($Section = Section::getByCode($this->sis_section_id))
        ) {
            throw new OutOfBoundsException("section '{$this->sis_section_id}' not found");
        }

        if (
            $this->sis_user_id
            && !($Person = User::getByUsername($this->sis_user_id))
        ) {
            throw new OutOfBoundsException("user '{$this->sis_user_id}' not found");
        }

        // build scope and query Slate records
        $slateConditions = [];

        if ($Section) {
            $slateConditions['CourseSectionID'] = $Section->ID;
        }

        if ($Person) {
            $slateConditions['PersonID'] = $Person->ID;
        }

        $slateResult = SectionParticipant::getAllByWhere($slateConditions, ['order' => 'ID']);

        // build scope and query Canvas records
        if ($Section) {
            $query = [];

            if ($Person) {
                $query['user_id'] = "sis_user_id:{$Person->Username}";
            }

            $canvasResult = $this->enrollmentsRepository->getBySection("sis_section_id:{$Section->Code}", $query);
        } elseif ($Person) {
            $canvasResult = $this->enrollmentsRepository->getByUser("sis_user_id:{$Person->Username}");
        }

        // index Slate enrollments
        $slateEnrollments = [];
        foreach ($slateResult as $slateEnrollment) {
            $key = implode('/', [
                $slateEnrollment->Person->Username,
                $slateEnrollment->Section->Code,
                static::$toCanvasRole[$slateEnrollment->Role],
            ]);

            $slateEnrollments[$key] = $slateEnrollment;
        }

        // index Canvas enrollments
        $canvasEnrollments = [];
        foreach ($canvasResult as $canvasEnrollment) {
            $key = implode('/', [
                $canvasEnrollment['sis_user_id'],
                $canvasEnrollment['sis_section_id'],
                $canvasEnrollment['role'],
            ]);

            $canvasEnrollments[$key] = $canvasEnrollment;
        }

        // plan operations
        $now = time();

        // plan operations -- enrollments to conclude/inactivate
        $inactivateQueue = array_diff_key($canvasEnrollments, $slateEnrollments);
        foreach ($inactivateQueue as $canvasEnrollment) {
            // skip if section has no SIS (Slate) identifier
            if (empty($canvasEnrollment['sis_section_id'])) {
                $this->logger->warning('Enrollment #{enrollmentId} is in a section (course #{courseId}, section #{sectionId} with no SIS ID, skipping...', [
                    'enrollmentId' => $canvasEnrollment['id'],
                    'courseId' => $canvasEnrollment['course_id'],
                    'sectionId' => $canvasEnrollment['course_section_id']
                ]);
                continue;
            }

            // leave extra observer enrollments alone
            if ('ObserverEnrollment' == $canvasEnrollment['role']) {
                // only skip observers who are observing a current student
                if (
                    !empty($canvasEnrollment['associated_user_id'])
                    && ($associatedUser = $this->usersRepository->getById($canvasEnrollment['associated_user_id']))
                    && ($associatedEnrollmentKey = "{$associatedUser['sis_user_id']}/{$canvasEnrollment['sis_section_id']}/StudentEnrollment")
                    && isset($slateEnrollments[$associatedEnrollmentKey])
                    && ($associatedEnrollment = $slateEnrollments[$associatedEnrollmentKey])
                    && (!$associatedEnrollment->StartDate || $associatedEnrollment->getEffectiveStartTimestamp() < $now)
                    && (!$associatedEnrollment->EndDate || $associatedEnrollment->getEffectiveEndTimestamp() > $now)
                ) {
                    continue;
                }
            }

            // skip if enrollment is already concluded
            if ('completed' == $canvasEnrollment['enrollment_state']) {
                continue;
            }

            yield new ConcludeEnrollment($canvasEnrollment);
        }

        // plan operations -- enrollments to activate
        $createQueue = array_diff_key($slateEnrollments, $canvasEnrollments);
        foreach ($createQueue as $slateEnrollment) {
            // skip if past end date
            if (
                $slateEnrollment->EndDate
                && $slateEnrollment->getEffectiveEndTimestamp() < $now
            ) {
                continue;
            }

            yield new ActivateEnrollment(
                "sis_user_id:{$slateEnrollment->Person->Username}",
                "sis_section_id:{$slateEnrollment->Section->Code}",
                static::$toCanvasRole[$slateEnrollment->Role],
                $this->buildCanvasValues($slateEnrollment)
            );
        }

        // plan operations -- enrollments to update
        $compareQueue = array_intersect_key($slateEnrollments, $canvasEnrollments);
        foreach ($compareQueue as $key => $slateEnrollment) {
            $canvasEnrollment = $canvasEnrollments[$key];

            // skip if section has no SIS (Slate) identifier
            if (empty($canvasEnrollment['sis_section_id'])) {
                $this->logger->warning('Enrollment #{enrollmentId} is in a section (course #{courseId}, section #{sectionId} with no SIS ID, skipping...', [
                    'enrollmentId' => $canvasEnrollment['id'],
                    'courseId' => $canvasEnrollment['course_id'],
                    'sectionId' => $canvasEnrollment['course_section_id']
                ]);
                continue;
            }

            // inactivate if end date has past
            if (
                $this->inactivateEnded
                && $slateEnrollment->EndDate
                && $slateEnrollment->getEffectiveEndTimestamp() < $now
            ) {
                // skip if already inactive
                if ('inactive' != $canvasEnrollment['enrollment_state']) {
                    yield new InactivateEnrollment($canvasEnrollment);
                }

                continue;
            }

            // build canvas attributes
            $newCanvasValues = $this->buildCanvasValues($slateEnrollment);

            // activate if needed
            if ($canvasEnrollment['enrollment_state'] != 'active') {
                yield new ActivateEnrollment(
                    "sis_user_id:{$slateEnrollment->Person->Username}",
                    "sis_section_id:{$slateEnrollment->Section->Code}",
                    $canvasEnrollment['role'],
                    $newCanvasValues
                );
                continue;
            }

            // skip if everything matches
            if (
                $canvasEnrollment['start_at'] == $newCanvasValues['start_at']
                && $canvasEnrollment['end_at'] == $newCanvasValues['end_at']
            ) {
                continue;
            }

            yield new UpdateEnrollment(
                "sis_user_id:{$slateEnrollment->Person->Username}",
                "sis_section_id:{$slateEnrollment->Section->Code}",
                $canvasEnrollment['role'],
                $newCanvasValues,
                array_intersect_key($canvasEnrollment, $newCanvasValues)
            );
        }
    }

    private function buildCanvasValues(SectionParticipant $slateEnrollment)
    {
        $endAt = $slateEnrollment->EndDate
        ? $slateEnrollment->getEffectiveEndTimestamp()
        : null;

        // end_at will be ignored if start_at isn't set too"
        $startAt = $endAt || $slateEnrollment->StartDate
            ? $slateEnrollment->getEffectiveStartTimestamp()
            : null;

        return [
            'start_at' => API::formatTimestamp($startAt),
            'end_at' => API::formatTimestamp($endAt),
        ];
    }
}
