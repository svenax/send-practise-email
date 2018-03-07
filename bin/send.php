#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;

class Note
{
    private $data;

    public function __construct(array $data)
    {
        $this->data = implode(PHP_EOL, $data);
    }

    public function get($regex)
    {
        return preg_match("/{$regex}/m", $this->data, $matches) ? $matches[1] : false;
    }

    public function getClean($regex)
    {
        return trim(strip_tags($this->get($regex)));
    }

    public function getType($regex)
    {
        $type = $this->get($regex);

        if ($type === false) return false;

        $tags = array_filter(explode(', ', $type), function ($i) {
            return !in_array($i, ['Öva', 'Fixme', '@fixme']);
        });

        return implode(', ', $tags);
    }
}

class Send
{
    private $tunes = [];

    public function __construct()
    {
        $results = $this->callGeeknote('find --notebook MPD --tag Öva --guid');

        foreach ($results as $result) {
            $matches = [];
            if (preg_match('/^([-0-9a-f]+) :/', $result, $matches)) {
                $note = new Note($this->callGeeknote('show ' . $matches[1]));

                $this->tunes[] = (object)[
                    'title' => $note->get('TITLE #+\n(.*)\n=+ META'),
                    'type' => $note->getType('CONTENT -+\nTags: ([^\n]*)\n'),
                    'text' => $note->getClean('CONTENT -+\nTags: [^\n]*\n(.*)'),
                ];
            }
        }

        usort($this->tunes, function ($a, $b) {
            return $a->title <=> $b->title;
        });
    }

    public function run()
    {
        if (empty($this->tunes)) return;

        $mailer = $this->newMailer();
        $mailer->addAddress(getenv('MAIL_TO'));
        $mailer->isHTML(true);

        $mailer->Subject = 'Öva era latmaskar!';
        $mailer->Body    = $this->makeBody();
        $mailer->AltBody = strip_tags($mailer->Body);

        if (!$mailer->send()) {
            echo 'Message could not be sent.' . PHP_EOL;
            echo 'Mailer Error: ' . $mailer->ErrorInfo . PHP_EOL;
            exit(1);
        }
    }

    private function makeBody()
    {
        $tunelist = '';
        foreach ($this->tunes as $tune) {
            $tunelist .= "<li>{$this->formatTune($tune)}</li>" . PHP_EOL;
        }
        $now = new DateTime();
        $then = new DateTime('thursday 19:00');
        if ($now > $then) {
            $then->add(new DateInterval('P7D'));
        }
        $diff = $then->diff($now);
        $h = $diff->format('%a') * 24 + $diff->format('%h');

        return <<<HTML
<p>Dessa låtar har vi bestämt att lägga extra krut på.
Du hittar dem i Evernote eller på <a href="http://svenax.net/site/sheetmusic/">min hemsida</a>.</p>
<ul>
{$tunelist}
</ul>
<p>Det är bara {$h} timmar tills nästa rep, så <em>sätt dig att öva nu!</em></p>

<p>mvh,<br>
Slavdrivarämbetet</p>
HTML;
    }

    private function formatTune($tune) {
        $res  = $tune->title;
        $res .= $tune->type ? " ({$tune->type})" : '';
        $res .= $tune->text ? "\n<br><em>{$tune->text}</em>" : '';

        return $res;
    }

    private function callGeeknote($params)
    {
        $res = [];
        exec('/usr/local/bin/geeknote ' . $params, $res);

        if (strpos($res[0], 'rate limit') !== false) {
            echo $res[0], PHP_EOL;
            exit(1);
        }

        return $res;
    }

    private function newMailer()
    {
        $mailer = new PHPMailer();
        $mailer->Mailer = 'smtp';
        $mailer->CharSet = 'UTF-8';
        $mailer->SMTPDebug = 0;

        $mailer->isSMTP();
        $mailer->SMTPAuth = true;
        $mailer->Host = getenv('MAIL_HOST');
        $mailer->Username = getenv('MAIL_USER');
        $mailer->Password = getenv('MAIL_PASS');

        $mailer->setFrom(getenv('MAIL_FROM'), getenv('MAIL_NAME'));
        $mailer->ReturnPath = getenv('MAIL_FROM');

        return $mailer;
    }
}

// Doit ======================================================================
(new Dotenv(dirname(__DIR__)))->load();
(new Send())->run();
