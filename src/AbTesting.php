<?php

namespace Ben182\AbTesting;

use Ben182\AbTesting\Models\Goal;
use Illuminate\Support\Collection;
use Ben182\AbTesting\Models\Experiment;
use Ben182\AbTesting\Events\GoalCompleted;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Ben182\AbTesting\Events\ExperimentNewVisitor;
use Ben182\AbTesting\Exceptions\InvalidConfiguration;

use Illuminate\Support\Facades\Cookie;

class AbTesting
{
    protected $experiments;

    const COOKIE_PERIOD = 60 * 24 * 180;
    const SESSION_KEY_EXPERIMENT = 'ab_testing_experiment';
    const SESSION_KEY_GOALS = 'ab_testing_goals';

    public function __construct()
    {
        $this->experiments = new Collection;
    }

    /**
     * Validates the config items and puts them into models.
     *
     * @return void
     */
    protected function start()
    {
        $configExperiments = config('ab-testing.experiments');
        $configGoals = config('ab-testing.goals');

        if (! count($configExperiments)) {
            throw InvalidConfiguration::noExperiment();
        }

        if (count($configExperiments) !== count(array_unique($configExperiments))) {
            throw InvalidConfiguration::experiment();
        }

        if (count($configGoals) !== count(array_unique($configGoals))) {
            throw InvalidConfiguration::goal();
        }

        foreach ($configExperiments as $configExperiment) {
            $this->experiments[] = $experiment = Experiment::firstOrCreate([
                'name' => $configExperiment,
            ], [
                'visitors' => 0,
            ]);

            foreach ($configGoals as $configGoal) {
                $experiment->goals()->firstOrCreate([
                    'name' => $configGoal,
                ], [
                    'hit' => 0,
                ]);
            }
        }

        Cookie::queue(self::SESSION_KEY_GOALS, new Collection, self::COOKIE_PERIOD);
    }

    /**
     * Triggers a new visitor. Picks a new experiment and saves it to the cookie.
     *
     * @return \Ben182\AbTesting\Models\Experiment|void
     */
    public function pageView()
    {
        if (config('ab-testing.ignore_crawlers') && (new CrawlerDetect)->isCrawler()) {
            return;
        }

        if (Cookie::get(self::SESSION_KEY_EXPERIMENT)) {
            return;
        }

        $this->start();
        $this->setNextExperiment();

        event(new ExperimentNewVisitor($this->getExperiment()));

        return $this->getExperiment();
    }

    /**
     * Calculates a new experiment and sets it to the cookie.
     *
     * @return void
     */
    protected function setNextExperiment()
    {
        $next = $this->getNextExperiment();
        $next->incrementVisitor();

        Cookie::queue(self::SESSION_KEY_EXPERIMENT, $next, self::COOKIE_PERIOD);
    }

    /**
     * Calculates a new experiment.
     *
     * @return \Ben182\AbTesting\Models\Experiment|null
     */
    protected function getNextExperiment()
    {
        $sorted = $this->experiments->sortBy('visitors');

        return $sorted->first();
    }

    /**
     * Checks if the currently active experiment is the given one.
     *
     * @param string $name The experiments name
     *
     * @return bool
     */
    public function isExperiment(string $name)
    {
        $this->pageView();

        return $this->getExperiment()->name === $name;
    }

    /**
     * Completes a goal by incrementing the hit property of the model and setting its ID in the cookie.
     *
     * @param string $goal The goals name
     *
     * @return \Ben182\AbTesting\Models\Goal|false
     */
    public function completeGoal(string $goal)
    {
        if (! $this->getExperiment()) {
            $this->pageView();
        }

        $goal = $this->getExperiment()->goals->where('name', $goal)->first();

        if (! $goal) {
            return false;
        }

        if (Cookie::get(self::SESSION_KEY_GOALS)->contains($goal->id)) {
            return false;
        }

        Cookie::get(self::SESSION_KEY_GOALS)->push($goal->id);

        $goal->incrementHit();
        event(new GoalCompleted($goal));

        return $goal;
    }

    /**
     * Returns the currently active experiment.
     *
     * @return \Ben182\AbTesting\Models\Experiment|null
     */
    public function getExperiment()
    {
        return Cookie::get(self::SESSION_KEY_EXPERIMENT);
    }

    /**
     * Returns all the completed goals.
     *
     * @return \Illuminate\Support\Collection|false
     */
    public function getCompletedGoals()
    {
        if (! Cookie::get(self::SESSION_KEY_GOALS)) {
            return false;
        }

        return Cookie::get(self::SESSION_KEY_GOALS)->map(function ($goalId) {
            return Goal::find($goalId);
        });
    }
}
