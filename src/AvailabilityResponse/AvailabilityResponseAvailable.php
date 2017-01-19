<?php

namespace Drupal\commerce\AvailabilityResponse;

class AvailabilityResponseAvailable extends AvailabilityResponse {

  /**
   * Constructs a new AvailabilityResponseAvailable object.
   *
   * @param int $min
   *   The minimum available.
   * @param int $max
   *   The maximum available.
   */
  public function __construct($min, $max) {
    $this->minimum = $min;
    $this->maximum = $max;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable() {
    return TRUE;
  }

}