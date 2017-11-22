<?php

namespace Drupal\mailjet_event\Event;

use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Event that is fired when cron maintenance tasks are performed.
 *
 * @see rules_cron()
 */

class BlockedEvent extends GenericEvent {

  const EVENT_NAME = 'blocked_event';

}
