Adhoc task tool
==================

Adhoc task management interface for Moodle 3.3+.
This plugin allows you to report on and manage failing adhoc tasks and to monitor/test adhoc tasks during development.
It also provides CLI utilities for running large batches of adhoc tasks at once to offload from cron, for example during annual rollover.
Finally it supports custom queue plugins (e.g. redis/beanstalk) for installations that need a little more from the adhoc queue system than a cron runner.
