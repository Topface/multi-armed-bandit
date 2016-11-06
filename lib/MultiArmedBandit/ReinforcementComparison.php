<?php

namespace MultiArmedBandit;

use Predis\Client;

class ReinforcementComparison extends AbstractPredisMultiArmedBandit {

    private $referenceRewardStep;

    private $startingReferenceReward;

    private $preferenceStep;

    private $temperature;

    private $receiveRewardScript =<<<SCRIPT
local moveCount = redis.call('hget', KEYS[1], KEYS[2])
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
redis.call('hset', KEYS[1], KEYS[6], math.exp(newPref/ARGV[4]))
SCRIPT;

    /**
     * @var PredisScriptHelper
     */
    private $PredisScriptHelper;

    public function getReferenceRewardName() {
        return $this->group . 'rr';
    }

    public function getPreferenceName($actionName) {
        return $this->group . 'p:' . $actionName;
    }

    public function getEPreferenceName($actionName) {
        return $this->group . 'ep:' . $actionName;
    }

    /**
     * ReinforcementComparison constructor.
     * @param Client $PredisStorage
     * @param string $learning
     * @param float $referenceRewardStep
     * @param float $startingReferenceReward
     * @param float $preferenceDiscountingStep
     * @param float $temperature
     * @param string $prefix
     */
    public function __construct(
        Client  $PredisStorage,
        string  $learning,
        float   $referenceRewardStep = 0.1,
        float   $startingReferenceReward = 0.0,
        float   $preferenceDiscountingStep = 0.1,
        float   $temperature = 1.0,
        string  $prefix = ''
    ) {
        parent::__construct($PredisStorage, $learning, $prefix);
        $this->referenceRewardStep = $referenceRewardStep;
        $this->startingReferenceReward = $startingReferenceReward;
        $this->preferenceStep = $preferenceDiscountingStep;
        $this->temperature = $temperature;
    }

    public function initAction(string $actionName) {
        parent::initAction($actionName);
        $this->PredisStorage->hset($this->learning, $this->getReferenceRewardName(), $this->startingReferenceReward);
        $this->PredisStorage->hset($this->learning, $this->getPreferenceName($actionName), 0);
        $this->PredisStorage->hset($this->learning, $this->getEPreferenceName($actionName), 1);
    }

    public function getBestActionIndex(array $actionNames) {
        $softmaxWeightNames = [];
        foreach ($actionNames as $action) {
            $softmaxWeightNames[] = $this->getEPreferenceName($action);
        }
        $softmaxWeights = $this->PredisStorage->hmget($this->learning, $softmaxWeightNames);
        $softmaxSum = array_sum($softmaxWeights);
        foreach ($softmaxWeights as &$softmaxWeight) {
            $softmaxWeight /= $softmaxSum;
        }
        unset($softmaxWeight);
        $actionIndex = self::getRandomByProbability($softmaxWeights);

        $this->PredisStorage->hincrby($this->learning, $this->getChooseCountName($actionNames[$actionIndex]), 1);
        return $actionIndex;
    }

    public function receiveReward(string $actionName, float $reward) {
        //TODO: вообще, проверить, что может сломаться, если на сервер упадет метеорит
        //TODO: хранить количество показов, чтобы знать активность группы
        //TODO: test on redis cluster

        $evalshaArgs = [
            null,           //script hash goes here
            6,
/*1_______*/$this->learning,
/*2_______*/$this->getChooseCountName($actionName),
/*3_______*/$this->getStoredRewardName($actionName),
/*4_______*/$this->getReferenceRewardName(),
/*5_______*/$this->getPreferenceName($actionName),
/*6_______*/$this->getEPreferenceName($actionName),
            $reward,
            $this->referenceRewardStep,
            $this->preferenceStep,
            $this->temperature
        ];

        if (!isset($this->PredisScriptHelper)) {
            $this->PredisScriptHelper = new PredisScriptHelper($this->PredisStorage, $this->receiveRewardScript);
        }
        $this->PredisScriptHelper->evalsha($evalshaArgs);
    }

    public function getActionState(string $actionName) {
        // TODO: Implement getActionState() method.
        throw new \BadMethodCallException('Not implemented');
    }
}
