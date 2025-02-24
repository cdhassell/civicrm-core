{* file to handle db changes in 5.66.alpha1 during upgrade *}

{* Ensure action_schedule.name has a unique value *}
UPDATE `civicrm_action_schedule` SET name = CONCAT('reminder_', id) WHERE name IS NULL OR name = '';
UPDATE `civicrm_action_schedule` a1, `civicrm_action_schedule` a2
SET a2.name = CONCAT(a2.name, '_', a2.id)
WHERE a2.name = a1.name AND a2.id > a1.id;
