<?php

namespace SubscriptionDigest\Command;

use DateTime;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;

class RunCommand extends Command
{
    /**
     * @var string
     */
    private $rootDirectory;
    /**
     * @var Environment
     */
    private $twig;
    /**
     * @var Mailer
     */
    private $mailer;

    public function __construct(string $rootDirectory, Environment $twig, Mailer $mailer)
    {
        parent::__construct();
        $this->rootDirectory = $rootDirectory;
        $this->twig = $twig;
        $this->mailer = $mailer;
    }

    public function configure(): void
    {
        $this
            ->setName('run')
            ->addOption('github', null, InputOption::VALUE_NONE, "Run GitHub API calls to determine last run")
            ->addOption('email', null, InputOption::VALUE_NONE, "Email the subscriptions digest")
        ;
    }


    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $github = $input->getOption('github');
        $email  = $input->getOption('email');

        if (empty(getenv('CONFIG_FILE'))) {
            echo "CONFIG_FILE is empty\n";
            exit(1);
        }

        $configurationPath = file_exists($this->rootDirectory . DIRECTORY_SEPARATOR . getenv('CONFIG_FILE')) ?
            $this->rootDirectory . DIRECTORY_SEPARATOR . getenv('CONFIG_FILE') :
            getenv('CONFIG_FILE');

        $configurationYaml = file_get_contents($configurationPath);

        if (false === $configurationYaml) {
            echo "Could not read contents of CONFIG_FILE\n";
            exit(1);
        }

        $configuration = Yaml::parse($configurationYaml);

        $client = new Client();
        $lastRun = null;
        if ($github) {
            $lastRun = $this->getLastGitHubRun($client);
        }

        if (false === is_null($lastRun)) {
            printf("Last run: %s \n", $lastRun->format('Y-m-d H:i:s'));
        }

        $failed = [];
        $data = [];
        foreach ($configuration['subscriptions'] as $subscription) {
            $feedData = [
                'title' => null,
                'entries' => [],
                'options' => $this->getOptions($subscription)
            ];

            try {
                $response = $client->get($feedData['options']['url']);
            } catch (GuzzleException $e) {
                $failed[] = [
                    'subscription' => $feedData['options']['url'],
                    'error' => $e->getMessage()
                ];

                continue;
            }

            $dom = new DOMDocument('1.0','UTF-8');
            $dom->loadXML($response->getBody()->getContents());

            assert(false === is_null($dom->documentElement));

            if ($dom->documentElement->tagName === "subscription") {
                $feedData['title'] = $this->getNodeValue($dom->getElementsByTagName('title'), 0);
                $updatedAt = new DateTime($this->getNodeValue($dom->getElementsByTagName('updated'), 0));

                if ($lastRun !== null && $lastRun > $updatedAt) {
                    break;
                }

                /** @var DOMElement $entry */
                foreach ($dom->getElementsByTagName('entry') as $entry) {
                    $entryPublishedAt = new DateTime($this->getNodeValue($dom->getElementsByTagName('updated'), 0));

                    if ($lastRun !== null && $lastRun > $entryPublishedAt) {
                        continue;
                    }

                    $link = $entry->getElementsByTagName('link')->item(0);
                    assert(false === is_null($link));

                    $feedData['entries'][] = [
                        'title'   => $this->getNodeValue($entry->getElementsByTagName('title'), 0),
                        'summary' => $this->getNodeValue($entry->getElementsByTagName('summary'), 0),
                        'link'    => $link->getAttribute('href')
                    ];
                }
            }

            if ($dom->documentElement->tagName === "rss") {
                $feedData['title'] = $this->getNodeValue($dom->getElementsByTagName('title'), 0);

                /** @var DOMElement $entry */
                foreach ($dom->getElementsByTagName('item') as $entry) {
                    $entryPublishedAt = new DateTime($this->getNodeValue($entry->getElementsByTagName('pubDate'), 0));

                    if ($lastRun !== null && $lastRun > $entryPublishedAt) {
                        continue;
                    }

                    $feedData['entries'][] = [
                        'title'   => $this->getNodeValue($entry->getElementsByTagName('title'), 0),
                        'summary' => $this->getNodeValue($entry->getElementsByTagName('description'), 0),
                        'link'    => $this->getNodeValue($entry->getElementsByTagName('link'), 0)
                    ];
                }
            }

            $data[] = $feedData;
        }

        if (empty($data) && empty($failed)) {
            echo "Feeds do not contain any new posts\n";
            exit(0);
        }

        try {
            $template = $this->twig->render('template.html.twig', [
                'data' => $data,
                'failed' => $failed
            ]);
        } catch (Exception $e) {
            echo $e->getMessage();
            exit(1);
        }

        file_put_contents('/tmp/digest.html', $template);


        if ($email) {
            if (empty(getenv('MAILER_RECIPIENTS'))) {
                echo "MAILER_RECIPIENTS is empty\n";
                exit(1);
            }

            if (empty(getenv('MAILER_SENDER'))) {
                echo "MAILER_SENDER is empty\n";
                exit(1);
            }

            $recipients = array_map('trim', explode(",", getenv('MAILER_RECIPIENTS')));

            $email = new Email();
            $email->subject('Subscriptions Digest');
            $email->from(getenv('MAILER_SENDER'));

            foreach ($recipients as $recipient) {
                $email->addTo($recipient);
            }

            $email->html($template);

            try {
                $this->mailer->send($email);
            } catch (TransportExceptionInterface $e) {
                echo $e->getMessage();
                exit(1);
            }
        }

        return 0;
    }


    private function getLastGitHubRun(Client $client): ?DateTime
    {
        try {
            $response = $client->get(sprintf(
                'https://api.github.com/repos/%s/actions/runs',
                getenv('GITHUB_REPOSITORY')
            ),[
                'headers' => [
                    'Accept' => 'application/vnd.github.v3+json',
                    'Authorization' => sprintf('token %s', getenv('GITHUB_TOKEN'))
                ]
            ]);

            $runsData = json_decode($response->getBody()->getContents(), true);

            foreach ($runsData['workflow_runs'] as $run) {
                if ($run['name'] === "Scheduled Digest" && $run['status'] === "completed") {
                    return new DateTime($run['created_at']);
                }
            }

            return null;
        } catch (GuzzleException $e) {
            echo "Failed when trying to fetch action run history\n";
            exit(1);
        } catch (Exception $e) {
            echo "An error occurred while trying to retrieve run data\n";
            exit(1);
        }
    }


    /**
     * @param mixed $subscription
     * @return array<string, mixed>
     */
    private function getOptions($subscription): array
    {
        if (is_string($subscription)) {
            return [
                'url' => $subscription,
                'no_summary' => false,
                'no_group_title' => false
            ];
        } else {
            return [
                'url' => $subscription['url'],
                'no_summary' => isset($subscription['summary']) && trim($subscription['summary']) === 'none',
                'no_group_title' => isset($subscription['group_title']) && trim($subscription['group_title']) === 'none'
            ];
        }
    }

    /**
     * @param DOMNodeList<DOMNode> $dnl
     * @param int $index
     * @return string
     */
    private function getNodeValue(DOMNodeList $dnl, int $index): string
    {
        if (false === ($dnl->length > $index)) {
            return "";
        }

        $e = $dnl->item($index);

        return is_null($e) ? "" : $e->nodeValue;
    }
}

/**
     Copyright (C) 2021  Marius Ghita

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
