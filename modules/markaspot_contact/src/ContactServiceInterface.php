<?php

namespace Drupal\markaspot_contact;

/**
 * Interface for contact service.
 */
interface ContactServiceInterface {

  /**
   * Validates the input data for contact form submission.
   *
   * @param array $data
   *   The submitted data array.
   *
   * @return array
   *   An array of error messages. Empty if validation passes.
   */
  public function validateInput(array $data): array;

  /**
   * Submits a contact form message.
   *
   * @param array $data
   *   The submitted data with keys: name, mail, message, gdpr.
   *
   * @return array
   *   Result array with 'success' boolean and 'message' string.
   *   On failure, may also include 'errors' array or 'code' integer.
   */
  public function submitContactForm(array $data): array;

  /**
   * Returns metadata about the contact form.
   *
   * @return array
   *   An array with keys: active, form_label, gdpr_required.
   */
  public function getFormInfo(): array;

}
