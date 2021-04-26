Simple project to automates the generation of digest emails for personal subscriptions.

## Motivation

With a preference towards minimalism, and dislike of ads, I had to dismiss almost all free feed readers I could find for my phone. Having been also a subcriber of [Kale Davis's HackeNewsletter](https://hackernewsletter.com/) for many years now, I tend to also like the digest style format for emails. With this project I wanted to find the middle ground between a feed reader and the aggregated links email format.

## Overview

GitHub Actions have been setup for a scheduled digest email generation daily at 00:00 (UTC), and a repository fork update every Friday. On the first run the digest email will include all the results from the configured feeds, while on follow runs it will use the GitHub API to only fetch articles published since the last time the Action ran.

The reason for the automated fork sync is because I think of this project as if it were an application, and I know I would prefer my applications to auto update than having to do the update manually. If any new features/updates are made they will try to be backwards compatible 🤞. The automation itself can be disabled from the UI if autoupdates is not something you prefer.

## Getting Started

You need to fork the repository, create a configuration file and setup the necessary repository secrets for the automation.

### Configuration file

The configuration format is YAML (of course), and an example file is located within the repository [config.example.yml](./config.example.yml). The only options supported are the ones described in the example file. 

Your configuration file can be committed to the repository, but the preferred alternative is to create a [Gist](https://gist.github.com) which will be referenced in the repository secrets. You can [permalink to a Gist's latest version](https://gist.github.com/atenni/5604615).

With a configuration file setup you can define the `CONFIG_FILE` repository secret to point at your Gist raw config.yml URL.

### Mailer configuration

`MAILER_SENDER` repository secret which specifies the email address that should be used as the From address of the email.

`MAILER_RECIPIENTS` repository secret with a list of comma separated list of recipients (`x@example.com`, `x@example.com,y@example.com`, ` x@example.com , y@example.com` are all valid values, as the script trims any extra whitespace).

`MAILER_DSN` repository secret with your emailer connection settings. Because the symfony Mailer component is used, you can use standard [SMTP/sendmail](https://symfony.com/doc/current/mailer.html#using-built-in-transports), but for more reliable transport and delivery I recommend one of the following providers. 


| Provider | SMTP | HTTP | API |
|----------|------|------|-----|
| Amazon SES | ses+smtp://USERNAME:PASSWORD@default  | ses+https://ACCESS_KEY:SECRET_KEY@default | ses+api://ACCESS_KEY:SECRET_KEY@default |
| Google Gmail | gmail+smtp://USERNAME:PASSWORD@default | n/a | n/a |
| Mailchimp Mandrill | mandrill+smtp://USERNAME:PASSWORD@default | mandrill+https://KEY@default | mandrill+api://KEY@default |
| Mailgun | mailgun+smtp://USERNAME:PASSWORD@default | mailgun+https://KEY:DOMAIN@default | mailgun+api://KEY:DOMAIN@default |
| Mailjet | mailjet+smtp://ACCESS_KEY:SECRET_KEY@default | n/a | mailjet+api://ACCESS_KEY:SECRET_KEY@default |
| Postmark | postmark+smtp://ID@default | n/a | postmark+api://KEY@default |
| Sendgrid | sendgrid+smtp://KEY@default | n/a | sendgrid+api://KEY@default |
| Sendinblue | sendinblue+smtp://USERNAME:PASSWORD@default | n/a | sendinblue+api://KEY@default |

> **Make sure you URL-encode your credentials**


### Test run

To make sure everything is wired up correctly, head over to your Actions > Scheduled Digest workflow and trigger the first run manually. Followup runs will pick up the last completed run and only email your entries posted since.

If you encounter any issues, or need to reset things, just delete the past workflow runs.


## See also

Coincidentally in the same weekend I was cleaning up the repository before sharing it publicly another GitHub automated
project that dealt with feeds was released. If you're looking for a reader like experience instead check out [osmofeed](https://github.com/osmoscraft/osmosfeed)
