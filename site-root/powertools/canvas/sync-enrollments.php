<?php

use Emergence\People\PeopleRequestHandler;
use Slate\Connectors\Canvas\API;
use Slate\Connectors\Canvas\Repositories\Enrollments as EnrollmentsRepository;
use Slate\Connectors\Canvas\Repositories\Users as UsersRepository;
use Slate\Connectors\Canvas\Strategies\PushEnrollments;
use Slate\Courses\SectionsRequestHandler;

// dump shim
if (!function_exists('dump')) {
    function dump()
    {
        // do nothing
    }
}

// configure request/response
$GLOBALS['Session']->requireAccountLevel('Administrator');
set_time_limit(0);
header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');
ob_end_flush();

// get input scope
if (empty($_GET['section'])) {
    $Section = null;
} elseif (!$Section = SectionsRequestHandler::getRecordByHandle($_GET['section'])) {
    throw new OutOfBoundsException(sprintf('section "%s" not found', $_GET['section']));
}

if (empty($_GET['person'])) {
    $Person = null;
} elseif (!$Person = PeopleRequestHandler::getRecordByHandle($_GET['person'])) {
    throw new OutOfBoundsException(sprintf('person "%s" not found', $_GET['person']));
} elseif (!$Person->Username) {
    throw new OutOfBoundsException(sprintf('person "%s" cannot be synced with canvas because they do not have a Username assigned', $_GET['person']));
}

// create logger
class _PowertoolLogger extends Psr\Log\AbstractLogger
{
    public $entries = [];

    public function log($level, $message, array $context = [])
    {
        $this->entries[] = compact('level', 'message', 'context');
        // dump([$level => Emergence\Logger::interpolate($message, $context)]);
    }
}
$logger = new _PowertoolLogger();

?>

<style>
    .sync-log article {
        margin-bottom: 1em;
    }
    .sync-log dl {
        margin-left: 2em;
        margin-top: 0;
    }
    .sync-log code {
        font-weight: bold;
    }

    .sync-log .level-emergency  { color: darkred; font-weight: bold; }
    .sync-log .level-alert      { color: darkred; font-weight: bold; }
    .sync-log .level-critical   { color: red; font-weight: bold; }
    .sync-log .level-error      { color: orangered; font-weight: bold; }
    .sync-log .level-warning    { color: orangered; }
    .sync-log .level-notice     { color: orange; }
    .sync-log .level-info       { color: skyblue; }
    .sync-log .level-debug      { color: lightgray; display: none; }
</style>


<h2>Strategy</h2>

<form method="GET">
    <fieldset>
        <legend>Analyze a set of enrollments</legend>
        <div>
            <label>
                Section: <input type="text" name="section" value="<?=!empty($_GET['section']) ? htmlspecialchars($_GET['section']) : ''; ?>">
            </label>
        </div>
        <div>
            <label>
                Person: <input type="text" name="person" value="<?=!empty($_GET['person']) ? htmlspecialchars($_GET['person']) : ''; ?>">
            </label>
        </div>
        <div>
            <label>
                <input type="checkbox" name="inactivate_ended" value="yes" <?=!empty($_GET['inactivate_ended']) && 'yes' == $_GET['inactivate_ended'] ? 'CHECKED' : ''; ?>>
                Inactivate enrollments with past end dates
            </label>
        </div>
        <div>
            <input type="submit" value="Prepare plan">
        </div>
    </fieldset>
</form>

<?php
// create push strategy
try {
    $strategy = new PushEnrollments(
        $logger,
        [
            'sis_section_id' => $Section ? $Section->Code : null,
            'sis_user_id' => $Person ? $Person->Username : null,
            'inactivate_ended' => !empty($_GET['inactivate_ended']) && 'yes' == $_GET['inactivate_ended'],
        ]
    );
} catch (\Exception $e) {
    dump(['strategy not ready' => $e]);
}
?>

<?php if ($strategy) : ?>
    <?php dump($strategy); ?>


    <h2>Plan</h2>
    <?php
    ob_flush();
    flush();
    $commands = [];
    ?>

    <section class="sync-log">
    <?php foreach ($strategy->plan() as $command) : ?>
        <?php
        // dump($command);
        list($message, $context) = $command->describe();
        $logText = Emergence\Logger::interpolate($message, $context);
        $logHtml = htmlspecialchars($logText);
        $logHtml = preg_replace('/(sis_[a-z]+_id:)(\S+)/', '$1<code>$2</code>', $logHtml);
        ?>

        <article class="level-notice">
            <div><?=$logHtml; ?></div>

            <?php
            // store for later debugging
            $commands[] = $command;

            // add to log
            $logger->log('info', $message, $context);

            // flush output
            ob_flush();
            flush();
            ?>
        </article>
    <?php endforeach; ?>

    <?php dump(compact('commands')); ?>


    <h2>Execution</h2>
    <form method="POST">
        <input type="submit" name="execute" value="Execute plan">
    </form>
    <?php if (!empty($_POST['execute'])) : ?>
        <script>
            window.scrollTo(0,document.body.scrollHeight);
        </script>

        <?php foreach ($commands as $command) : ?>
            <?php
                $request = $command->buildRequest();

                try {
                    $response = API::execute($request);
                } catch (Exception $e) {
                    $response = $e;
                }

                dump([
                    'command' => $command,
                    'request' => $request,
                    'response' => $response,
                ]);
            ?>
        <?php endforeach; ?>
        <!--
        <h2>Repositories</h2>
        <?php /* dump(compact('usersRepository', 'enrollmentsRepository')) */ ?>
        -->
    <?php endif; ?>


    <h2>Diagnostic Log</h2>
    <?php dump($logger->entries); ?>

<?php endif; ?>
