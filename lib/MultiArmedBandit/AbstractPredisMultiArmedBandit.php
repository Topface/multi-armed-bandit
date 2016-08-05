<?php

namespace MultiArmedBandit;

use Predis\Client;

abstract class AbstractPredisMultiArmedBandit extends AbstractMultiArmedBandit {

    /**
     * @var Client
     */
    protected $PredisStorage;

    /**
     * @var string Used to run several experiments simultaniously
     */
    protected $predisHashKey;

    /**
     * @var string Used to divide users by groups within one experiment
     */
    protected $prefix;

    public function getChooseCountName($actionName){
        return $this->prefix . 'cc:' . $actionName;
    }

    /**
     * @param Client $PredisStorage
     * @param string $predisHashKey
     * @param string $prefix
     */
    public function __construct(
        Client $PredisStorage,
        $predisHashKey,
        $prefix
    ) {
        $this->PredisStorage = $PredisStorage;
        $this->predisHashKey = $predisHashKey;
        $this->prefix = $prefix;
    }
    
    public function initAction($actionName) {
        $this->PredisStorage->hset($this->predisHashKey, $this->getChooseCountName($actionName), 0);
    }
}