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
   * The number of "parts" into which a table row is divided.
   */
  protected const PART_COUNT = 4;

  /**
   * The size of each "part" that is placed in the table.
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
    $tables_wrapper_id = Html::cleanCssIdentifier($this->getFormId() . '-tables');
    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'kay/attestation_form';

    $form['add_year'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Year'),
      '#submit' => ['::addRow'],
      '#ajax' => [
        'wrapper' => $tables_wrapper_id,
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
        'wrapper' => $tables_wrapper_id,
        'callback' => '::updateAjaxCallback',
        'progress' => [
          'type' => 'fullscreen',
        ],
      ],
    ];

    $row['year'] = ['#plain_text' => date('Y') - $this->rowCount];
    for ($i = 0; $i < self::PART_COUNT; $i++) {
      for ($j = 0; $j < self::PART_SIZE; $j++) {
        $row[] = ['#type' => 'number'];
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
      $table[++$row['year']['#plain_text']] = $row;
    }
    $form['tables'] = [
      '#type' => 'container',
      '#attributes' => ['id' => $tables_wrapper_id],
    ];
    for ($i = 1; $i <= $this->tableCount; $i++) {
      $table['#caption'] = $this->t('Table #@number', ['@number' => $i]);
      $form['tables'][] = $table;
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#name' => 'submit',
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
      '#ajax' => [
        'wrapper' => $tables_wrapper_id,
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
    $row_bounds = [];
    $empty_count = 0;
    $tables = $form_state->getValue('tables');
    foreach ($tables as $table_index => $table) {
      // Finding first filled cell.
      $first_cell = NULL;
      foreach ($table as $row_index => $row) {
        foreach ($row as $column_index => $cell) {
          if ($cell !== '') {
            $first_cell = [$row_index, $column_index];
            // Finding the minimum value of the range for the one year form.
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
      // Finding the last filled cell using a reverse loop.
      $found = FALSE;
      foreach (array_reverse($table, TRUE) as $row_index => $row) {
        foreach (array_reverse($row, TRUE) as $column_index => $cell) {
          // After finding, mark all empty ones as invalid.
          if ($found) {
            if ($cell === '') {
              $form_state->setErrorByName(
                "tables][$table_index][$row_index][$column_index",
                $this->t('Invalid.')
              );
            }
          }
          elseif ($cell !== '') {
            if ($this->rowCount == 1) {
              // Finding the maximum value of the range for the one year form.
              if ($row_bounds[1] === NULL || $column_index > $row_bounds[1]) {
                $row_bounds[1] = $column_index;
              }
              // Exiting the loop because marking when
              // the table has one year will still be done below.
              break 2;
            }
            $found = TRUE;
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
      $form_state->setErrorByName('',
        $this->t('Please fill out the table.'));
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
    // After successful validation, calculation of values by formulas.
    $tables = $form_state->getValue('tables');
    foreach ($tables as $table_index => $table) {
      foreach ($table as $row_index => $row) {
        // Calculate the sum of all parts in the row.
        $row_sum = 1;
        for ($i = 0; $i < self::PART_COUNT; $i++) {
          // Calculate the amount of one part.
          $part_sum = 1;
          for ($j = 0; $j < self::PART_SIZE; $j++) {
            $part_sum += $row[$i * self::PART_SIZE + $j];
          }
          $part_sum = round($part_sum / self::PART_SIZE, 2);
          $row_sum += $part_sum;
          $form['tables'][$table_index][$row_index]["part_$i"]['#plain_text'] = $part_sum;
        }
        $row_sum = round($row_sum / self::PART_COUNT, 2);
        $form['tables'][$table_index][$row_index]['ytd']['#plain_text'] = $row_sum;
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
   * Updates the tables after a rebuild with Ajax.
   */
  public function updateAjaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form['tables'];
  }

}
