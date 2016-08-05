<?php

namespace MultiArmedBandit;

// TODO: если movecount == 0, сохранять награду до следующего раза!
/**
 * Epsilon-greey algorithm with weighted average action values for nonstationary problems.
 */
class WeightedAverage extends AbstractPredisMultiArmedBandit {

    /**
     * @var float rate of greedy actions in range 0..1
     */
    private $greediness;

    /**
     * value[$action][i] = value[action][i-1] + $step * (reward - value[action][i-1])
     * @var float
     */
    private $step;

    /**
     * value[action][0]
     * @var float
     */
    private $startingValue;

    /**
     * @param string $actionName
     * @return string
     */
    public function getValueName($actionName) {
        return $this->prefix . "v:" . $actionName;
    }

    /**
     * @param \Predis\Client $PredisStorage
     * @param string $predisHashKey
     * @param float $greediness
     * @param float $step
     * @param float $startingValue
     * @param string $prefix
     */
    public function __construct(
        $PredisStorage,
        $predisHashKey,
        $greediness = 0.9,
        $step = 0.1,    //TODO: best default value
        $startingValue = 0.0,
        $prefix = ''
    ) {
        parent::__construct($PredisStorage, $predisHashKey, $prefix);
        $this->greediness = $greediness;
        $this->step = $step;
        $this->startingValue = $startingValue;
    }

    public function initAction($actionName) {
        parent::initAction($actionName);
        $this->PredisStorage->hset($this->predisHashKey, $this->getValueName($actionName), $this->startingValue);
    }

    public function getBestActionIndex(array $actionNames) {
        $actionTypeProbabilities = [$this->greediness, 1 - $this->greediness];
        $actionTypeIndex = self::getRandomByProbability($actionTypeProbabilities);
        $actionIndex = $actionTypeIndex == 0 ?
            $this->greedyAction($actionNames) :
            $this->randomAction($actionNames);
        $this->PredisStorage->hincrby($this->predisHashKey, $this->getChooseCountName($actionNames[$actionIndex]), 1);
        return $actionIndex;
    }

    public function receiveReward($actionName, $reward) {
        $luaUpdater =
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

local deltaValue = ARGV[2]*(reward - redis.call('hget', KEYS[1], KEYS[4]))
redis.call('hincrbyfloat', KEYS[1], KEYS[4], deltaValue)";

        $this->PredisStorage->eval(
            $luaUpdater,
            4,
/*1_______*/$this->predisHashKey,
/*2_______*/$this->getChooseCountName($actionName),
/*3_______*/$this->getStoredRewardName($actionName),
/*4_______*/$this->getValueName($actionName),
            $reward,
            $this->step
        );
    }

    /**
     * @param $actionNames
     * @return int
     */
    private function greedyAction($actionNames) {
        $valueNames = array_map(
            function($action) { return $this->getValueName($action); },
            $actionNames
        );
        $values = $this->PredisStorage->hmget($this->predisHashKey, $valueNames);
        $highestValues = array_keys($values, max($values));
        return (count($highestValues) > 1) ?
            $actionIndex = $highestValues[rand(0, count($highestValues) - 1)] :
            $highestValues[0];
    }

    private function randomAction($actionNames) {
        return rand(0, count($actionNames) - 1);
    }
}