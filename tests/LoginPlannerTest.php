<?php

namespace Slate\Connectors\Canvas\Tests;

use PHPUnit\Framework\TestCase;
use Slate\Connectors\Canvas\LoginPlanner;

class LoginPlannerTest extends TestCase
{
    const EMAIL = 'jdoe@example.org';
    const USERNAME = 'jdoe';

    public function testSingleLoginAlreadyCorrectPlansNothing()
    {
        $logins = [
            static::login(101, 'jdoe@example.org', 'jdoe'),
        ];

        $plan = static::plan($logins);

        $this->assertSame([], $plan['operations']);
        $this->assertSame([], $plan['warnings']);
    }

    public function testSingleLoginWithWrongEmailIsRenamed()
    {
        $logins = [
            static::login(101, 'jdoe-old@example.org', 'jdoe'),
        ];

        $plan = static::plan($logins);

        $this->assertSame(
            [
                [LoginPlanner::ACTION_RENAME, 101, ['login[unique_id]' => 'jdoe@example.org']],
            ],
            static::summarize($plan)
        );
        $this->assertSame(
            ['login[unique_id]' => 'jdoe-old@example.org'],
            $plan['operations'][0]['previous']
        );
    }

    public function testSingleLoginWithWrongEmailAndNoSisIdIsRenamedThenStamped()
    {
        $logins = [
            static::login(101, 'jdoe-old@example.org', null),
        ];

        $this->assertSame(
            [
                [LoginPlanner::ACTION_RENAME, 101, ['login[unique_id]' => 'jdoe@example.org']],
                [LoginPlanner::ACTION_SET_SIS_ID, 101, ['login[sis_user_id]' => 'jdoe']],
            ],
            static::summarize(static::plan($logins))
        );
    }

    public function testSecondLoginWithTargetEmailIsNotRenamedAndGetsTheSisId()
    {
        // e.g. after a Canvas user merge: old login holds the SIS ID, new login holds the email
        $logins = [
            static::login(101, 'jdoe-old@example.org', 'jdoe'),
            static::login(102, 'jdoe@example.org', null),
        ];

        $plan = static::plan($logins);

        $this->assertSame(
            [
                [LoginPlanner::ACTION_CLEAR_SIS_ID, 101, ['login[sis_user_id]' => '']],
                [LoginPlanner::ACTION_SET_SIS_ID, 102, ['login[sis_user_id]' => 'jdoe']],
            ],
            static::summarize($plan)
        );

        foreach ($plan['operations'] as $operation) {
            $this->assertArrayNotHasKey('login[unique_id]', $operation['changes'], 'no rename should be planned');
        }

        $result = static::apply($logins, $plan['operations']);
        $this->assertSame([102], static::loginIdsWithSisId($result, 'jdoe'), 'SIS ID should end up on exactly the sign-in login');
        $this->assertSame('jdoe-old@example.org', $result[101]['unique_id'], 'other login should be left in place');
    }

    public function testStaleSisIdOnOtherLoginIsClearedBeforeStamping()
    {
        $logins = [
            static::login(101, 'jdoe-old@example.org', 'jdoe-old'),
            static::login(102, 'jdoe@example.org', null),
        ];

        $plan = static::plan($logins);

        $this->assertSame(
            [
                [LoginPlanner::ACTION_CLEAR_SIS_ID, 101, ['login[sis_user_id]' => '']],
                [LoginPlanner::ACTION_SET_SIS_ID, 102, ['login[sis_user_id]' => 'jdoe']],
            ],
            static::summarize($plan)
        );
        $this->assertSame(
            ['login[sis_user_id]' => 'jdoe-old'],
            $plan['operations'][0]['previous']
        );

        $result = static::apply($logins, $plan['operations']);
        $this->assertSame([102], static::loginIdsWithSisId($result, 'jdoe'));
    }

    public function testStaleSisIdOnOtherLoginIsLeftAloneWhenSignInLoginIsCorrect()
    {
        $logins = [
            static::login(101, 'jdoe-old@example.org', 'jdoe-old'),
            static::login(102, 'jdoe@example.org', 'jdoe'),
        ];

        $this->assertSame([], static::plan($logins)['operations']);
    }

    public function testLoginCarryingUsernameIsRenamedWhenNoLoginHasTargetEmail()
    {
        $logins = [
            static::login(101, 'someone-else@example.org', null),
            static::login(102, 'jdoe-old@example.org', 'jdoe'),
        ];

        $this->assertSame(
            [
                [LoginPlanner::ACTION_RENAME, 102, ['login[unique_id]' => 'jdoe@example.org']],
            ],
            static::summarize(static::plan($logins))
        );
    }

    public function testFirstActiveLoginIsRenamedWhenNoLoginCarriesUsername()
    {
        $logins = [
            static::login(101, 'jdoe-suspended@example.org', null, 'suspended'),
            static::login(102, 'jdoe-old@example.org', null),
            static::login(103, 'jdoe-older@example.org', null),
        ];

        $this->assertSame(
            [
                [LoginPlanner::ACTION_RENAME, 102, ['login[unique_id]' => 'jdoe@example.org']],
                [LoginPlanner::ACTION_SET_SIS_ID, 102, ['login[sis_user_id]' => 'jdoe']],
            ],
            static::summarize(static::plan($logins))
        );
    }

    public function testEmailComparisonIsCaseInsensitiveAndTrimmed()
    {
        $logins = [
            static::login(101, ' JDoe@Example.ORG ', 'jdoe'),
        ];

        $this->assertSame([], static::plan($logins, ' JDOE@example.org')['operations']);
    }

    public function testInactiveLoginWithTargetEmailIsNotRenamedOverAndIsWarned()
    {
        $logins = [
            static::login(101, 'jdoe-old@example.org', 'jdoe'),
            static::login(102, 'jdoe@example.org', null, 'suspended'),
        ];

        $plan = static::plan($logins);

        foreach ($plan['operations'] as $operation) {
            $this->assertArrayNotHasKey('login[unique_id]', $operation['changes'], 'no rename should be planned');
        }

        $this->assertCount(1, $plan['warnings']);
        $this->assertSame(102, $plan['warnings'][0]['context']['canvasLoginId']);
    }

    public function testNoActiveLoginPlansNothingAndWarns()
    {
        $logins = [
            static::login(101, 'jdoe-old@example.org', null, 'suspended'),
        ];

        $plan = static::plan($logins);

        $this->assertSame([], $plan['operations']);
        $this->assertCount(1, $plan['warnings']);
    }

    public function testEmptyUsernameIsNeverStamped()
    {
        $logins = [
            static::login(101, 'jdoe@example.org', 'jdoe-old'),
        ];

        $this->assertSame([], static::plan($logins, self::EMAIL, '')['operations']);
    }

    public function testEmptyEmailIsNeverSetAsLoginId()
    {
        $logins = [
            static::login(101, 'jdoe-old@example.org', 'jdoe'),
        ];

        $plan = static::plan($logins, '');

        $this->assertSame([], $plan['operations']);
        $this->assertCount(1, $plan['warnings']);
    }

    public function testAlreadyInUseErrorsAreRecognized()
    {
        $this->assertTrue(LoginPlanner::isAlreadyInUseError(
            new \RuntimeException('Canvas reports: ID already in use for this account and authentication provider (unique_id)', 400)
        ));
        $this->assertTrue(LoginPlanner::isAlreadyInUseError(
            new \RuntimeException('Canvas reports: SIS ID "jdoe" is already in use (sis_user_id)', 400)
        ));
        $this->assertFalse(LoginPlanner::isAlreadyInUseError(
            new \RuntimeException('Canvas request failed with code 500', 500)
        ));
        $this->assertFalse(LoginPlanner::isAlreadyInUseError(
            new \LogicException('ID already in use')
        ));
    }

    private static function plan(array $logins, $email = self::EMAIL, $username = self::USERNAME)
    {
        return LoginPlanner::plan(['id' => 1], $logins, $email, $username);
    }

    private static function login($id, $uniqueId, $sisUserId, $workflowState = 'active')
    {
        return [
            'id' => $id,
            'user_id' => 1,
            'account_id' => 1,
            'unique_id' => $uniqueId,
            'sis_user_id' => $sisUserId,
            'workflow_state' => $workflowState,
        ];
    }

    /**
     * Reduce a plan's operations to [action, loginId, changes] for compact assertions.
     */
    private static function summarize(array $plan)
    {
        return array_map(function ($operation) {
            return [$operation['action'], $operation['loginId'], $operation['changes']];
        }, $plan['operations']);
    }

    /**
     * Simulate executing operations in order against Canvas's per-account
     * uniqueness rules, failing if any write would claim an ID still in use.
     */
    private static function apply(array $logins, array $operations)
    {
        $result = [];
        foreach ($logins as $login) {
            $result[$login['id']] = $login;
        }

        foreach ($operations as $operation) {
            foreach ($operation['changes'] as $key => $value) {
                $field = substr($key, 6, -1);

                if ('' !== $value) {
                    foreach ($result as $id => $login) {
                        if ($id != $operation['loginId'] && strtolower((string) $login[$field]) === strtolower($value)) {
                            throw new \RuntimeException("{$field} {$value} is still in use by login {$id}");
                        }
                    }
                }

                $result[$operation['loginId']][$field] = $value;
            }
        }

        return $result;
    }

    private static function loginIdsWithSisId(array $logins, $sisUserId)
    {
        return array_keys(array_filter($logins, function ($login) use ($sisUserId) {
            return $login['sis_user_id'] === $sisUserId;
        }));
    }
}
