<?php

namespace Drupal\kay\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a Exam form.
 */
class AttestationForm extends FormBase {

  /**
   * The number of "quarters" into which the table is divided.
   */
  protected const PART_COUNT = 4;

  /**
   * The size of the "quarters" that are placed in the table.
   */
  protected const PART_SIZE = 3;

  /**
   * The current number of tables in the form.
   *
   * @var int
   */
  protected int $tableCount = 1;

  /**
   * The current number of rows in the tables in the form.
   *
   * @var int
   */
  protected int $rowCount = 1;

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $wrapper_id = Html::cleanCssIdentifier($this->getFormId() . '-wrapper');
    $form['#prefix'] = "<div id='$wrapper_id'>";
    $form['#suffix'] = '</div>';
    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'kay/attestation_form';

    $form['add_year'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Year'),
      '#submit' => ['::addRow'],
      '#ajax' => [
        'wrapper' => $wrapper_id,
        'callback' => '::updateAjaxCallback',
        'progress' => [
          'type' => 'fullscreen',
        ],
      ],
    ];
    $form['add_table'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Table'),
      '#submit' => ['::addTable'],
      '#ajax' => [
        'wrapper' => $wrapper_id,
        'callback' => '::updateAjaxCallback',
        'progress' => [
          'type' => 'fullscreen',
        ],
      ],
    ];

    $row['year'] = ['#plain_text' => date('Y') - $this->rowCount];
    for ($i = 0; $i < self::PART_COUNT; $i++) {
      for ($j = 0; $j < self::PART_SIZE; $j++) {
        $row[] = [
          '#type' => 'number',
        ];
      }
      $row["part_$i"] = ['#plain_text' => ''];
    }
    $row['ytd'] = ['#plain_text' => ''];
    $table = [
      '#type' => 'table',
      '#header' => [
        $this->t('Year'),
        $this->t('Jan'),
        $this->t('Feb'),
        $this->t('Mar'),
        $this->t('Q1'),
        $this->t('Apr'),
        $this->t('May'),
        $this->t('Jun'),
        $this->t('Q2'),
        $this->t('Jul'),
        $this->t('Aug'),
        $this->t('Sep'),
        $this->t('Q3'),
        $this->t('Oct'),
        $this->t('Nov'),
        $this->t('Dec'),
        $this->t('Q4'),
        $this->t('YTD'),
      ],
    ];
    for ($i = 0; $i < $this->rowCount; $i++) {
      $table[$row['year']['#plain_text']] = $row;
      $row['year']['#plain_text']++;
    }
    $form['tables'] = array_fill(0, $this->tableCount, $table);

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#name' => 'submit',
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#ajax' => [
        'wrapper' => $wrapper_id,
        'callback' => '::updateAjaxCallback',
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kay_attestation';
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Prevent validation when adding a table or row.
    if ($form_state->getTriggeringElement()['#name'] != 'submit') {
      return;
    }
    $tables = $form_state->getValue('tables');
    $row_bounds = [];
    $empty_count = 0;
    foreach ($tables as $table_index => $table) {
      // Finding first filled cell.
      $first_cell = NULL;
      foreach ($table as $row_index => $row) {
        foreach ($row as $column_index => $cell) {
          if ($cell !== '') {
            $first_cell = [$row_index, $column_index];
            // Finding the maximum range for one year.
            if ($row_bounds[0] === NULL || $column_index < $row_bounds[0]) {
              $row_bounds[0] = $column_index;
            }
            break 2;
          }
        }
      }
      // There is no point in checking a completely empty table.
      if (!$first_cell) {
        $empty_count++;
        continue;
      }
      $found = FALSE;
      for ($row = end($table); ($row_index = key($table)) !== NULL; $row = prev($table)) {
        for ($cell = end($row); ($column_index = key($row)) !== NULL; $cell = prev($row)) {
          // Finding last filled cell.
          if ($found) {
            if ($cell === '') {
              $form_state->setErrorByName(
                "tables][$table_index][$row_index][$column_index",
                $this->t('Invalid.')
              );
            }
          }
          elseif ($cell !== '') {
            $found = TRUE;
            // Finding the maximum range for one year.
            if ($row_bounds[1] === NULL || $column_index > $row_bounds[1]) {
              $row_bounds[1] = $column_index;
            }
          }
          // Exit from the loop when the first filled cell is reached.
          if ($first_cell == [$row_index, $column_index]) {
            break 2;
          }
        }
      }
    }
    // Set error when all tables is empty.
    if ($empty_count == $this->tableCount) {
      $form_state->setErrorByName('', t('Please fill out the table.'));
    }
    elseif ($this->rowCount == 1) {
      // If the form has one year, the period of all rows must match.
      foreach ($tables as $table_index => $table) {
        foreach ($table as $row_index => $row) {
          // Marks all empty cells by the maximum found range.
          for ($column_index = $row_bounds[0]; $column_index <= $row_bounds[1]; $column_index++) {
            if ($row[$column_index] === '') {
              $form_state->setErrorByName(
                "tables][$table_index][$row_index][$column_index",
                $this->t('Invalid.')
              );
            }
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $tables = $form_state->getValue('tables');
    foreach ($tables as $table_index => $table) {
      foreach ($table as $row_index => $row) {
        $year_sum = 0;
        for ($i = 0; $i < self::PART_COUNT; $i++) {
          $part_sum = 0;
          for ($j = 0; $j < self::PART_SIZE; $j++) {
            $part_sum += $row[$i * self::PART_SIZE + $j];
          }
          $part_sum = round(($part_sum + 1) / self::PART_SIZE, 2);
          $year_sum += $part_sum;
          $form['tables'][$table_index][$row_index]["part_$i"]['#plain_text'] = $part_sum;
        }
        $year_sum = round(($year_sum + 1) / self::PART_COUNT, 2);
        $form['tables'][$table_index][$row_index]['ytd']['#plain_text'] = $year_sum;
      }
    }
    $this->messenger()->addStatus($this->t('Valid.'));
  }

  /**
   * Adds a row to the tables in the form.
   */
  public function addRow(array &$form, FormStateInterface $form_state) {
    $this->rowCount++;
    $form_state->setRebuild();
  }

  /**
   * Adds a table to the form.
   */
  public function addTable(array &$form, FormStateInterface $form_state) {
    $this->tableCount++;
    $form_state->setRebuild();
  }

  /**
   * Updates the form after a rebuild with Ajax.
   */
  public function updateAjaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

}
