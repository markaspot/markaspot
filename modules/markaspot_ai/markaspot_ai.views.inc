<?php

/**
 * @file
 * Views integration for Mark-a-Spot AI module.
 *
 * Provides Views data definitions for custom AI tables.
 *
 * Tables: markaspot_ai_duplicate_matches, markaspot_ai_sentiment.
 */

declare(strict_types=1);

/**
 * Implements hook_views_data().
 */
function markaspot_ai_views_data(): array {
  $data = [];

  // -- markaspot_ai_duplicate_matches table --
  $data['markaspot_ai_duplicate_matches']['table']['group'] = t('AI Duplicate Matches');

  $data['markaspot_ai_duplicate_matches']['table']['base'] = [
    'field' => 'id',
    'title' => t('AI Duplicate Matches'),
    'help' => t('Contains detected duplicate service request pairs.'),
  ];

  $data['markaspot_ai_duplicate_matches']['id'] = [
    'title' => t('Match ID'),
    'help' => t('The unique match record identifier.'),
    'field' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
  ];

  $data['markaspot_ai_duplicate_matches']['source_nid'] = [
    'title' => t('Source node ID'),
    'help' => t('The node ID of the source service request.'),
    'field' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
    'relationship' => [
      'title' => t('Source node'),
      'help' => t('The source service request node.'),
      'base' => 'node_field_data',
      'base field' => 'nid',
      'id' => 'standard',
      'label' => t('Source node'),
    ],
  ];

  $data['markaspot_ai_duplicate_matches']['match_nid'] = [
    'title' => t('Match node ID'),
    'help' => t('The node ID of the matching service request.'),
    'field' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
    'relationship' => [
      'title' => t('Match node'),
      'help' => t('The matching service request node.'),
      'base' => 'node_field_data',
      'base field' => 'nid',
      'id' => 'standard',
      'label' => t('Match node'),
    ],
  ];

  $data['markaspot_ai_duplicate_matches']['similarity_score'] = [
    'title' => t('Similarity score'),
    'help' => t('Cosine similarity score between embeddings (0-1).'),
    'field' => [
      'id' => 'numeric',
      'float' => TRUE,
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
  ];

  $data['markaspot_ai_duplicate_matches']['distance_meters'] = [
    'title' => t('Distance (meters)'),
    'help' => t('Geographic distance between the two requests in meters.'),
    'field' => [
      'id' => 'numeric',
      'float' => TRUE,
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
  ];

  $data['markaspot_ai_duplicate_matches']['status'] = [
    'title' => t('Status'),
    'help' => t('Match review status: pending, confirmed, or rejected.'),
    'field' => [
      'id' => 'standard',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'in_operator',
      'options callback' => '_markaspot_ai_duplicate_status_options',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  $data['markaspot_ai_duplicate_matches']['reviewed_by'] = [
    'title' => t('Reviewed by'),
    'help' => t('The user who reviewed the match.'),
    'field' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'relationship' => [
      'title' => t('Reviewer'),
      'help' => t('The user who reviewed the duplicate match.'),
      'base' => 'users_field_data',
      'base field' => 'uid',
      'id' => 'standard',
      'label' => t('Reviewer'),
    ],
  ];

  $data['markaspot_ai_duplicate_matches']['reviewed_at'] = [
    'title' => t('Reviewed at'),
    'help' => t('The date and time the match was reviewed.'),
    'field' => [
      'id' => 'date',
    ],
    'sort' => [
      'id' => 'date',
    ],
    'filter' => [
      'id' => 'date',
    ],
  ];

  $data['markaspot_ai_duplicate_matches']['created'] = [
    'title' => t('Created'),
    'help' => t('The date and time the match was detected.'),
    'field' => [
      'id' => 'date',
    ],
    'sort' => [
      'id' => 'date',
    ],
    'filter' => [
      'id' => 'date',
    ],
  ];

  // -- markaspot_ai_sentiment table --
  $data['markaspot_ai_sentiment']['table']['group'] = t('AI Sentiment Analysis');

  $data['markaspot_ai_sentiment']['table']['base'] = [
    'field' => 'id',
    'title' => t('AI Sentiment Analysis'),
    'help' => t('Contains sentiment analysis results for service requests.'),
  ];

  $data['markaspot_ai_sentiment']['id'] = [
    'title' => t('Sentiment ID'),
    'help' => t('The unique sentiment record identifier.'),
    'field' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
  ];

  $data['markaspot_ai_sentiment']['entity_type'] = [
    'title' => t('Entity type'),
    'help' => t('The entity type (e.g. node).'),
    'field' => [
      'id' => 'standard',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
  ];

  $data['markaspot_ai_sentiment']['entity_id'] = [
    'title' => t('Entity ID'),
    'help' => t('The entity ID of the analyzed service request.'),
    'field' => [
      'id' => 'numeric',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
    'argument' => [
      'id' => 'numeric',
    ],
    'relationship' => [
      'title' => t('Service request'),
      'help' => t('The service request node that was analyzed.'),
      'base' => 'node_field_data',
      'base field' => 'nid',
      'id' => 'standard',
      'label' => t('Service request'),
    ],
  ];

  $data['markaspot_ai_sentiment']['sentiment'] = [
    'title' => t('Sentiment'),
    'help' => t('Sentiment category: frustrated, neutral, or positive.'),
    'field' => [
      'id' => 'standard',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'in_operator',
      'options callback' => '_markaspot_ai_sentiment_options',
    ],
    'argument' => [
      'id' => 'string',
    ],
  ];

  $data['markaspot_ai_sentiment']['score'] = [
    'title' => t('Score'),
    'help' => t('Sentiment score from -1.0 (frustrated) to 1.0 (positive).'),
    'field' => [
      'id' => 'numeric',
      'float' => TRUE,
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
  ];

  $data['markaspot_ai_sentiment']['confidence'] = [
    'title' => t('Confidence'),
    'help' => t('Confidence level from 0 to 1.'),
    'field' => [
      'id' => 'numeric',
      'float' => TRUE,
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'numeric',
    ],
  ];

  $data['markaspot_ai_sentiment']['reasoning'] = [
    'title' => t('Reasoning'),
    'help' => t('Brief explanation of the sentiment classification.'),
    'field' => [
      'id' => 'standard',
    ],
    'sort' => [
      'id' => 'standard',
    ],
    'filter' => [
      'id' => 'string',
    ],
  ];

  $data['markaspot_ai_sentiment']['analyzed_at'] = [
    'title' => t('Analyzed at'),
    'help' => t('The date and time the sentiment was analyzed.'),
    'field' => [
      'id' => 'date',
    ],
    'sort' => [
      'id' => 'date',
    ],
    'filter' => [
      'id' => 'date',
    ],
  ];

  return $data;
}

/**
 * Provides status options for the duplicate match status filter.
 *
 * @return array
 *   An associative array of status options.
 */
function _markaspot_ai_duplicate_status_options(): array {
  return [
    'pending' => t('Pending'),
    'confirmed' => t('Confirmed'),
    'rejected' => t('Rejected'),
  ];
}

/**
 * Provides sentiment options for the sentiment filter.
 *
 * @return array
 *   An associative array of sentiment options.
 */
function _markaspot_ai_sentiment_options(): array {
  return [
    'frustrated' => t('Frustrated'),
    'neutral' => t('Neutral'),
    'positive' => t('Positive'),
  ];
}
