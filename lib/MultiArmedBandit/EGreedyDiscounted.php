<?php

namespace MultiArmedBandit;

use Predis\Client;

/**
 * Epsilon-greey algorithm with weighted average action values for nonstationary problems.
 */
class EGreedyDiscounted extends AbstractPredisMultiArmedBandit {

    /**
     * @var float rate of greedy actions in range 0..1
     */
    private $explorationRate;

    /**
     * @var float values[$action][i] = values[action][i-1] + $discountingStep * (reward - values[action][i-1])
     */
    private $discountingStep;

    /**
     * @var float values[action][0]
     */
    private $startingValue;

    /**
     * @var int Count this amount of actions as one.
     * values[$action][$i] =
     *     sum(realValues[$action][$i * $bulkSize] ... realValue[$action][($i+1) * $bulkSize - 1]) / $bulkSize
     */
    private $bulkSize;

    /**
     * @var string
     */
    private $chooseActionScript = <<<SCRIPT
local chooseCount = redis.call('hincrby', KEYS[1], KEYS[2], 1)
if tonumber(chooseCount) % tonumber(ARGV[1]) ~= 0 then
    return
end

local reward = redis.call('hget', KEYS[1], KEYS[3]) / ARGV[1]
local delta = ARGV[2]*(reward - redis.call('hget', KEYS[1], KEYS[4]))
redis.call('hincrbyfloat', KEYS[1], KEYS[4], delta)

redis.call('hset', KEYS[1], KEYS[3], 0)
SCRIPT;

    /**
     * @param string    $actionName
     * @return string
     */
    public function getValueName(string $actionName) {
        return $this->group . "v:" . $actionName;
    }

    /**
     * @param Client    $PredisStorage
     * @param string    $learning
     * @param string    $group
     * @param float     $explorationRate
     * @param float     $discountingStep
     * @param float     $startingValue
     * @param int       $bulkActionCount
     */
    public function __construct(
        Client  $PredisStorage,
        string  $learning,
        string  $group = '',
        float   $explorationRate = 0.1,
        float   $discountingStep = 0.1,    //TODO: best default value
        float   $startingValue = 0.0,
        int     $bulkActionCount = 1
    ) {
        parent::__construct($PredisStorage, $learning, $group);
        $this->explorationRate  = $explorationRate;
        $this->discountingStep  = $discountingStep;
        $this->startingValue    = $startingValue;
        $this->bulkSize         = $bulkActionCount;
    }

    /**
     * @param string    $actionName
     */
    public function initAction(string $actionName) {
        parent::initAction($actionName);
        $this->PredisStorage->hset($this->learning, $this->getValueName($actionName), $this->startingValue);
    }

    /**
     * @param array     $actionNames
     * @return int
     */
    public function getBestActionIndex(array $actionNames) {
        $actionTypeProbabilities = [$this->explorationRate, 1 - $this->explorationRate];
        $actionTypeIndex = self::getRandomByProbability($actionTypeProbabilities);
        $actionIndex = $actionTypeIndex == 0 ?
            $this->explore($actionNames) :
            $this->exploit($actionNames);

        $actionName = $actionNames[$actionIndex];
        $evalshaArgs = [
            null,           //script hash goes here
            4,
/*1_______*/$this->learning,
/*2_______*/$this->getChooseCountName($actionName),
/*3_______*/$this->getStoredRewardName($actionName),
/*4_______*/$this->getValueName($actionName),
            $this->bulkSize,
            $this->discountingStep,
        ];
        PredisScriptHelper::evalshaStatic($this->PredisStorage, $this->chooseActionScript, $evalshaArgs);

        return $actionIndex;
    }

    /**
     * @param string    $actionName
     * @param float     $reward
     */
    public function receiveReward(string $actionName, float $reward) {
        $this->PredisStorage->hincrbyfloat($this->learning, $this->getStoredRewardName($actionName), $reward);
    }

    /**
     * @param string    $actionName
     * @return array
     */
    public function getActionState(string $actionName) {
        $chooseCount = $this->PredisStorage->hget($this->learning, $this->getChooseCountName($actionName));
        $value = $this->PredisStorage->hget($this->learning, $this->getValueName($actionName));
        return [
            'chooseCount' => $chooseCount,
            'weightedAverage' => $value
        ];
    }

    /**
     * @param array     $actionNames
     * @return int
     */
    private function exploit(array $actionNames) {
        $valueNames = [];
        foreach ($actionNames as $action) {
            $valueNames[] = $this->getValueName($action);
        }
        $values = $this->PredisStorage->hmget($this->learning, $valueNames);
        $highestValues = array_keys($values, max($values));
        return (count($highestValues) > 1) ?
            $actionIndex = $highestValues[array_rand($highestValues)] :
            $highestValues[0];
    }

    /**
     * @param array     $actionNames
     * @return int
     */
    private function explore(array $actionNames) {
        return array_rand($actionNames);
    }
}
