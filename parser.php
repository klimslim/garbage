<?php

chdir('/tmp');

$c = new ProdLogParser();

class ProdLogParser
{
    const BASE_URL = 'http://localhost/';

    const DB_NAME = 'db2';
    const DB_USERNAME = 'db2inst1';
    const DB_PASSWORD = 'db2inst1';
    const DB_SCHEME = 'db2inst1';
    const DB_ADVISER_THRESHOLD = 75;

    const DBA_EMAIL = 'dba@example.com';

    private $current_file;

    public function __construct()
    {
        if ($r = $this->retrieve(self::BASE_URL)) {

            $d = new DOMDocument();
            $d->loadHTML($r);
            $l = $d->getElementsByTagName('a');
            foreach ($l as $n) {

                $u = $n->nodeValue;

                if (substr($u, 0, 8) == 'extract.' && substr($u, -4) == '.txt') {

                    $this->current_file = $u;

                    if (file_exists($u . '.processed')) continue;

                    if (file_exists($u)) {
                        $r = file_get_contents($u);
                    } else {
                        $w = self::BASE_URL . $u;
                        $r = $this->retrieve($w);
                        file_put_contents($u, $r);
                    }

                    $r = $this->parse($r);
                    $this->process($r);

                    file_put_contents($u . '.processed', microtime(true));
                }
            }
        }
    }

    private function retrieve($u)
    {
        $c = curl_init($u);
        curl_setopt_array($c, array(
            CURLOPT_RETURNTRANSFER => true
        ));
        $r = curl_exec($c);

        return $r;
    }

    private function parse($s)
    {
        $a = explode("\n", $s);
        $b = '';
        $m = '';
        $s = array();
        $f = false;

        foreach ($a as $l) {
            if (strpos($l, '[FATAL]')) {
                if (!empty($b)) {

                    $s[$m] = $b;
                    $b = '';
                    $m = '';
                    $f = false;
                }
            }
            if ($f) {

                $b .= $l;
                $b .= "\n";
            }
            if (strpos($l, 'Slow Query')) {
                $m = $l;
                $f = true;
                continue;
            }
        }

        return $s;
    }

    private function process($s)
    {
        $f = file_exists($this->current_file . '.mark');

        if ($f) {
            $m = file_get_contents($this->current_file . '.mark');
        } else {
            $m = '';
        }

        foreach ($s as $k => $l) {

            if ($f) {
                if ($k == $m) {
                    $f = false;
                } else {
                    continue;
                }
            }

            file_put_contents('issue.sql', $l . ';');
            $c = 'db2advis -l -1 -d ' . self::DB_NAME . ' -a ' . self::DB_USERNAME . '/' . self::DB_PASSWORD . ' -q ' . self::DB_SCHEME . ' -i issue.sql -o advice.sql -m MICP';
            exec($c, $o, $r);
            if ($r === 0) {
                foreach ($o as $l) {
                    if (preg_match('/ \[[-+]?([0-9]*\.[0-9]+|[0-9]+)%\] improvement/i', $l, $r)) {
                        if ($r[1] > self::DB_ADVISER_THRESHOLD) {
                            $this->report('ATTENTION: query improvement found: ' . $r[1], implode("\n", $o));
                        }
                        break;
                    }
                }
            } else {

                $c = !file_exists('advice.sql');
                if (!$c) {
                    $c = filemtime('advice.sql') < filemtime('issue.sql');
                }

                if ($c) {
                    file_put_contents('advice.sql', '');
                }

                $this->report('ATTENTION: adviser fault!', implode("\n", $o));
            }

            $m = $k;
            file_put_contents($this->current_file . '.mark', $m);

            unset($o, $r);
        }
    }

    private function report($s, $m)
    {
        $m = trim($m);

        $r = md5(date('r', time()));

        $sql = chunk_split(base64_encode(file_get_contents('issue.sql')));
        $advice = chunk_split(base64_encode(file_get_contents('advice.sql')));

        $h = <<<CLOB
From: adviser@example.com
Reply-To: adviser@example.com
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="boundary-parser-{$r}"

--boundary-parser-{$r}
Content-Type: text/plain;
Content-Transfer-Encoding: 7bit

{$m}

--boundary-parser-{$r}
Content-Type: text/plain; name="issue.sql" 
Content-Transfer-Encoding: base64 
Content-Disposition: attachment 

{$sql}

--boundary-parser-{$r}
Content-Type: text/plain; name="advice.sql" 
Content-Transfer-Encoding: base64 
Content-Disposition: attachment 

{$advice}

--boundary-parser-{$r}--
CLOB;

        @mail(self::DBA_EMAIL, $s, '', $h);
    }
}