<?php

namespace MultiArmedBandit;

/**
 * Base class for multi-armed bandits. Written for specific case with delayed feedback and rare rewards (e.g. advertising).
 */
abstract class AbstractMultiArmedBandit {

    /**
     * Initialize new action. Fills data storage with default values for $actionName
     * @param string    $actionName
     */
    public abstract function initAction(string $actionName);

    /**
     * Chooses best action according to a strategy, incrases it's "choose" counter and return it's index in $actionNames array.
     * @param array     $actionNames array of action names to choose from
     * @return int index of chosen action in $actionNames array
     */
    public abstract function getBestActionIndex(array $actionNames);

    /**
     * Updates $actionName's estimated value by $reward/<choose counter>, sets choose counter for $actionName to 0.
     * @param string    $actionName
     * @param float     $reward
     */
    public abstract function receiveReward(string $actionName, float $reward);

    /**
     * @param string    $actionName
     * @return array
     */
    public abstract function getActionState(string $actionName);

    /**
     * Given an array of probabilities of actions chooses one and returns it's index.
     * @param array     $actionProbabilities
     * @return int
     */
    public static function getRandomByProbability(array $actionProbabilities) {
        $sum = array_sum($actionProbabilities);
        $chance = rand() / getrandmax() * $sum;

        $actionIndex = 0;
        foreach ($actionProbabilities as $actionIndex => $actionProbability) {
            $chance -= $actionProbability;
            if ($chance <= 0)
                break;
        }
        return $actionIndex;
    }

    /**
     * Given an array of probabilities of actions chooses one and returns it's index. Sorts the array of actions before choosing.
     * @param array     $actionProbabilities
     * @return int
     */
    public static function getRandomByProbabilitySorted(array $actionProbabilities) {
        $sortedActionProbabilities = sort($actionProbabilities);
        $index = self::getRandomByProbability($sortedActionProbabilities);
        return array_search($sortedActionProbabilities[$index], $actionProbabilities);
    }
}
