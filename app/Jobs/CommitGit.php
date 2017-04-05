<?php

namespace App\Jobs;

use App\Exceptions\NothingToCommitException;
use App\Models\Formula;
use GitHub;
use Illuminate\Queue\SerializesModels;
use League\Uri\Schemes\Http;
use Log;
use SebastianBergmann\Git\Git;
use TQ\Git\Repository\Repository;

class CommitGit
{
    use SerializesModels;

    /**
     * @var Formula
     */
    protected $formula;

    /**
     * @var Git
     */
    protected $git;

    /**
     * @var Repository
     */
    protected $repository;

    /**
     * Create a new job instance.
     *
     * @param Formula $formula
     */
    public function __construct(Formula $formula)
    {
        $this->formula = $formula;

        $this->git = new Git($this->formula->getAttribute('git')['path']);

        $this->repository = Repository::open($this->formula->getAttribute('git')['path'], $this->binary());
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            // checkout homebrew repo to master branch
            $this->master()
                // create new branch for the release
                ->createBranch()
                // update homebrew repo formula
                ->modifyFormula()
                // commit homebrew repo
                ->commit()
                // push commit new remote tracked repository
                ->pushCommit()
                // create pull request to homebrew project
                ->openPullRequest()
                // checkout homebrew repo to master branch
                ->master();
        } catch (NothingToCommitException $e) {
            Log::error('nothing-to-commit', [
                'formula' => $this->formula->getAttribute('name'),
                'version' => $this->formula->getAttribute('version'),
            ]);

            // revert changes
            $this->revert();
        }
    }

    /**
     * Change branch to master.
     *
     * @return $this
     */
    protected function master()
    {
        $this->git->checkout('master');

        return $this;
    }

    /**
     * Create new branch for the update.
     *
     * @return $this
     */
    protected function createBranch()
    {
        $branch = sprintf('-b %s', $this->branchName());

        $this->git->checkout($branch);

        return $this;
    }

    /**
     * Modify formula url and hash.
     *
     * @return $this
     */
    protected function modifyFormula()
    {
        // get formula path
        $filename = sprintf('%s/%s.rb', $this->formula->getAttribute('git')['path'], mb_strtolower($this->name()));

        // get regex pattern
        $regex = $this->regex();

        // update formula's url and hash
        $content = preg_replace(
            $regex['patterns'],
            $regex['replacements'],
            file_get_contents($filename),
            1,
            $count
        );

        // if $count is zero, nothing change
        if (0 === $count) {
            throw new NothingToCommitException;
        }

        // write data to file
        file_put_contents($filename, $content, LOCK_EX);

        return $this;
    }

    /**
     * Get preg_replace regex.
     *
     * @return array
     */
    protected function regex()
    {
        $hash = explode(':', $this->formula->getAttribute('hash'), 2);

        $patterns = [
            '/url ".+"'.PHP_EOL.'/U',
            '/sha\d{3} ".+"'.PHP_EOL.'/U',
        ];

        $replacements = [
            sprintf('url "%s"%s', $this->archiveUrl(), PHP_EOL),
            sprintf('%s "%s"%s', $hash[0], $hash[1], PHP_EOL),
        ];

        return compact('patterns', 'replacements');
    }

    /**
     * Get archive url.
     *
     * @return string
     */
    protected function archiveUrl()
    {
        $repo = $this->repo();

        $pairs = [
            '{owner}' => $repo['user'],
            '{name}' => $repo['name'],
            '{version}' => $this->formula->getAttribute('version'),
        ];

        return strtr($this->formula->getAttribute('archive'), $pairs);
    }

    /**
     * Get repository name.
     *
     * @return array
     */
    protected function repo()
    {
        // create Uri\Schemes from url string
        // e.g. https://github.com/phpmyadmin/phpmyadmin
        $url = Http::createFromString($this->formula->getAttribute('url'));

        // get phpmyadmin/phpmyadmin from https://github.com/phpmyadmin/phpmyadmin
        $repo = substr($url->getPath(), 1);

        // return as an associative array
        return array_combine(['user', 'name'], explode('/', $repo));
    }

    /**
     * Commits the currently staged changes into the repository.
     *
     * @return $this
     */
    protected function commit()
    {
        $message = sprintf('%s %s', $this->name(), $this->formula->getAttribute('version'));

        $this->repository->add();

        $this->repository->commit($message);

        return $this;
    }

    /**
     * Push commit to GitHub.
     *
     * @return $this
     */
    protected function pushCommit()
    {
        $arguments = ['origin', $this->branchName()];

        $this->repository->getGit()->{'push'}($this->repository->getRepositoryPath(), $arguments);

        return $this;
    }

    /**
     * Open a pull request for homebrew repository.
     *
     * @return $this
     */
    protected function openPullRequest()
    {
        $github = $this->formula->getAttribute('git');

        GitHub::pullRequests()
            ->create($github['upstream']['owner'], $github['upstream']['repo'], [
                'title' => sprintf('%s %s', $this->name(), $this->formula->getAttribute('version')),
                'head'  => sprintf('%s:%s', $github['fork']['owner'], $this->branchName()),
                'base'  => 'master',
                'body'  => $this->pullRequestBody(),
            ]);

        return $this;
    }

    /**
     * Get pull request body.
     *
     * @return string
     */
    protected function pullRequestBody()
    {
        return <<<'EOF'
---

Pull request opened by [homebrew-updater](https://github.com/BePsvPT/homebrew-updater) project.
EOF;
    }

    /**
     * Revert to original state.
     *
     * @return $this
     */
    protected function revert()
    {
        // checkout to master branch
        $this->master();

        // delete the branch that we created
        $branch = sprintf('%s', $this->branchName());

        $arguments = ['-D', $branch];

        $this->repository->getGit()->{'branch'}($this->repository->getRepositoryPath(), $arguments);

        return $this;
    }

    /**
     * Get the pull request branch name.
     *
     * @return string
     */
    protected function branchName()
    {
        // branch name is combine with {repo-name}-{new-version}
        return sprintf('%s-%s', $this->name(), $this->formula->getAttribute('version'));
    }

    /**
     * Get the formula name.
     *
     * @return $this
     */
    protected function name()
    {
        $name = $this->formula->getAttribute('name');

        $pos = strrpos($name, '/');

        // if formula's name is homebrew/xxx/zzz, we only need `zzz`
        return false === $pos ? $name : substr($name, $pos + 1);
    }

    /**
     * Git binary file.
     *
     * @return string
     */
    protected function binary()
    {
        if (is_file('/usr/local/bin/git')) {
            return '/usr/local/bin/git';
        }

        return '/usr/bin/git';
    }
}
