<?php

namespace MultiArmedBandit;

/**
 * Base class for multi-armed bandits. Written for specific case with delayed feedback and rare rewards (e.g. advertising).
 */
abstract class AbstractMultiArmedBandit {

    /**
     * Initialize new action. Fills data storage with default values for $actionName
     * @param string $actionName
     */
    public abstract function initAction($actionName);

    /**
     * Chooses best action according to a strategy, incrases it's "choose" counter and return it's index in $actionNames array.
     * @param array $actionNames array of action names to choose from
     * @return int index of chosen action in $actionNames array
     */
    public abstract function getBestActionIndex(array $actionNames);

    /**
     * Updates $actionName's estimated value by $reward/<choose counter>, sets choose counter for $actionName to 0.
     * @param $actionName
     * @param $reward
     */
    public abstract function receiveReward($actionName, $reward);

    /**
     * Given an array of probabilities of actions chooses one and returns it's index.
     * @param array $actionProbabilities
     * @return int
     */
    public static function getRandomByProbability(array $actionProbabilities) {
        $sum = array_sum($actionProbabilities);
        $chance = rand() / getrandmax() * $sum;

        for ($i = 0; $i < count($actionProbabilities) && $chance > 0; $i++) {
            $chance -= $actionProbabilities[$i];
        }
        return $i - 1;
    }

    /**
     * Given an array of probabilities of actions chooses one and returns it's index. Sorts the array of actions before choosing.
     * @param array $actionProbabilities
     * @return int
     */
    public static function getRandomByProbabilitySorted(array $actionProbabilities) {
        $sortedActionProbabilities = sort($actionProbabilities);
        $index = self::getRandomByProbability($sortedActionProbabilities);
        return array_search($sortedActionProbabilities[$index], $actionProbabilities);
    }
}