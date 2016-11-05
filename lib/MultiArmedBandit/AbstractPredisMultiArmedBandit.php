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
    protected $learning;

    /**
     * @var string Used to divide users by groups within one experiment
     */
    protected $group;

    public static function removeLearningData(Client $PredisStorage, string $learning) {
        $PredisStorage->del($learning);
    }

    public function getChooseCountName($actionName){
        return $this->group . 'cc:' . $actionName;
    }

    public function getStoredRewardName($actionName) {
        return $this->group . 'sr:' . $actionName;
    }

    /**
     * @param Client $PredisStorage
     * @param string $learning
     * @param string $group
     */
    public function __construct(
        Client $PredisStorage,
        string $learning,
        string $group
    ) {
        $this->PredisStorage    = $PredisStorage;
        $this->learning         = $learning;
        $this->group            = $group;
    }
    
    public function initAction($actionName) {
        $this->PredisStorage->hset($this->learning, $this->getChooseCountName($actionName), 0);
        $this->PredisStorage->hset($this->learning, $this->getStoredRewardName($actionName), 0);
    }
}
