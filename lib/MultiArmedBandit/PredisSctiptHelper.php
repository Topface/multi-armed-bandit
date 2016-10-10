<?php

namespace MultiArmedBandit;

use Predis\Client;
use Predis\Response\ServerException;

class PredisSctiptHelper {
    private $Predis;
    private $script;
    private $scriptHash;

    /**
     * PredisSctiptHelper constructor.
     * @param $Predis Client
     * @param $script string
     * @param null $scriptHash string
     */
    public function __construct($Predis, $script, $scriptHash = null) {
        if (!isset($scriptHash))
            $scriptHash = sha1($script);

        $this->Predis = $Predis;
        $this->script = $script;
        $this->scriptHash = $scriptHash;
    }

    /**
     * @param $evalshaArgs [] Fills 0'th element with script hash
     * @throws ServerException
     */
    public function evalsha($evalshaArgs) {
        $evalshaArgs[0] = $this->scriptHash;
        try {
            $this->Predis->evalsha(...$evalshaArgs);
        } catch (ServerException $ex) {
            if ($ex->getErrorType() != 'NOSCRIPT')
                throw $ex;

            $this->Predis->script('load', $this->script);
            $this->Predis->evalsha(...$evalshaArgs);
        }

    }
}
