<?php

namespace MultiArmedBandit;

use Predis\Client;
use Predis\Response\ServerException;

class PredisScriptHelper {
    private $Predis;
    private $script;
    private $scriptHash;

    /**
     * PredisSctiptHelper constructor.
     * @param Client        $Predis
     * @param string        $script
     * @param string|null   $scriptHash
     */
    public function __construct(Client $Predis, string $script, string $scriptHash = null) {
        if (!isset($scriptHash))
            $scriptHash = sha1($script);

        $this->Predis = $Predis;
        $this->script = $script;
        $this->scriptHash = $scriptHash;
    }

    /**
     * @param array     $evalshaArgs Fills 0'th element with script hash
     * @throws ServerException
     */
    public function evalsha(array $evalshaArgs) {
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
