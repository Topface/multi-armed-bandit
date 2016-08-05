<?php

namespace MultiArmedBandit;

use Predis\Client;

class ReinforcementComparison extends AbstractPredisMultiArmedBandit {

    private $referenceRewardStep;

    private $startingReferenceReward;

    private $preferenceStep;

    private $temperature;

    private $receiveRewardScript =
"local moveCount = redis.call('hget', KEYS[1], KEYS[2])
if tonumber(moveCount) == 0 then
    redis.call('hincrby', KEYS[1], KEYS[3], ARGV[1])
    return
end
redis.call('hset', KEYS[1], KEYS[2], 0)
local storedReward = redis.call('hget', KEYS[1], KEYS[3])
if tonumber(storedReward) ~= 0 then
    redis.call('hset', KEYS[1], KEYS[3], 0)
end
local reward = (ARGV[1] + storedReward) / moveCount

local deltaReward = reward - redis.call('hget', KEYS[1], KEYS[4])
local res = redis.call('hincrbyfloat', KEYS[1], KEYS[4], ARGV[2]*deltaReward)
local newPref = redis.call('hincrbyfloat', KEYS[1], KEYS[5], ARGV[3]*deltaReward)
redis.call('hset', KEYS[1], KEYS[6], math.exp(newPref/ARGV[4]))";

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
        //TODO: test on redis cluster

        if (!$this->receiveRewardScriptLoaded) {
            $this->receiveRewardScriptHash = $this->loadPredisScript($this->receiveRewardScript);
            $this->receiveRewardScriptLoaded = true;
        }
        $this->PredisStorage->evalsha(
            $this->receiveRewardScriptHash,
            6,
/*1_______*/$this->predisHashKey,
/*2_______*/$this->getChooseCountName($actionName),
/*3_______*/$this->getStoredRewardName($actionName),
/*4_______*/$this->getReferenceRewardName(),
/*5_______*/$this->getPreferenceName($actionName),
/*6_______*/$this->getEPreferenceName($actionName),
            $reward,
            $this->referenceRewardStep,
            $this->preferenceStep,
            $this->temperature
        );
    }
}