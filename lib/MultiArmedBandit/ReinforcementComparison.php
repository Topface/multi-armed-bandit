<?php

namespace MultiArmedBandit;

use Predis\Client;

class ReinforcementComparison extends AbstractPredisMultiArmedBandit {

    private $referenceRewardStep;

    private $startingReferenceReward;

    private $preferenceStep;

    private $temperature;

    public function getReferenceRewardName() {
        return $this->prefix . 'rr';
    }

    public function getPreferenceName($actionName) {
        return $this->prefix . 'p:' . $actionName;
    }

    public function getEPreferenceName($actionName) {
        return $this->prefix . 'ep:' . $actionName;
    }

    /**
     * ReinforcementComparison constructor.
     * @param Client $PredisStorage
     * @param string $predisHashKey
     * @param float $referenceRewardStep
     * @param float $startingReferenceReward
     * @param float $preferenceStep
     * @param float $temperature
     * @param string $prefix
     */
    public function __construct(
        Client $PredisStorage,
        $predisHashKey,
        $referenceRewardStep = 0.1,
        $startingReferenceReward = 0.0,
        $preferenceStep = 0.1,
        $temperature = 1.0,
        $prefix = ''
    ) {
        parent::__construct($PredisStorage, $predisHashKey, $prefix);
        $this->referenceRewardStep = $referenceRewardStep;
        $this->startingReferenceReward = $startingReferenceReward;
        $this->preferenceStep = $preferenceStep;
        $this->temperature = $temperature;
    }

    public function initAction($actionName) {
        parent::initAction($actionName);
        $this->PredisStorage->hset($this->predisHashKey, $this->getReferenceRewardName(), $this->startingReferenceReward);
        $this->PredisStorage->hset($this->predisHashKey, $this->getPreferenceName($actionName), 0);
        $this->PredisStorage->hset($this->predisHashKey, $this->getEPreferenceName($actionName), 1);
    }

    public function getBestActionIndex(array $actionNames) {
        $softmaxWeightNames = array_map(
            function($val) { return $this->getEPreferenceName($val); },
            $actionNames
        );
        $softmaxWeights = $this->PredisStorage->hmget($this->predisHashKey, $softmaxWeightNames);
        $softmaxSum = array_sum($softmaxWeights);
        $softmaxWeights = array_map (
            function($el) use($softmaxSum) {
                return $el / $softmaxSum;
            },
            $softmaxWeights
        );
        $actionIndex = self::getRandomByProbability($softmaxWeights);

        $this->PredisStorage->hincrby($this->predisHashKey, $this->getChooseCountName($actionNames[$actionIndex]), 1);
        return $actionIndex;
    }

    public function receiveReward($actionName, $reward) {
        //TODO: вообще, проверить, что может сломаться, если на сервер упадет метеорит
        //TODO: хранить количество показов, чтобы знать активность группы
        //TODO: removed "if preference == false then preference = 0 end", because we have initAction() => throw exceptions
        //TODO: test on redis cluster
        // TODO: "if tonumber(moveCount) == 0 then moveCount = 1 end" or "if tonumber(moveCount) == 0 then return end" ?

        $luaUpdater =
"local moveCount = redis.call('hget', KEYS[1], KEYS[2])
redis.call('hset', KEYS[1], KEYS[2], 0)
if tonumber(moveCount) == 0 then moveCount = 1 end

local reward = ARGV[1] / moveCount
local deltaReward = reward - redis.call('hget', KEYS[1], KEYS[3])
local res = redis.call('hincrbyfloat', KEYS[1], KEYS[3], ARGV[2]*deltaReward)
local newPref = redis.call('hincrbyfloat', KEYS[1], KEYS[4], ARGV[3]*deltaReward)
redis.call('hset', KEYS[1], KEYS[5], math.exp(newPref/ARGV[4]))";

        //TODO: use EVALSHA or something
        return $this->PredisStorage->eval(
            $luaUpdater,
            5,
/*1_______*/$this->predisHashKey,
/*2_______*/$this->getChooseCountName($actionName),
/*3_______*/$this->getReferenceRewardName(),
/*4_______*/$this->getPreferenceName($actionName),
/*5_______*/$this->getEPreferenceName($actionName),
            $reward,
            $this->referenceRewardStep,
            $this->preferenceStep,
            $this->temperature
        );
    }
}