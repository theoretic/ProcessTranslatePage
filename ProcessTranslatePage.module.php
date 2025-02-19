<?php namespace ProcessWire;

/**
 * Translate all textfields on a page via Fluency
 *
 */
class ProcessTranslatePage extends Process implements Module {
    private $fluency;
    private $sourceLanguage;
    private $targetLanguages = [];
    private $excludedTemplates = [];
    private $excludedFields = [];
    private $excludedLangauges = [];
    private $adminTemplates = ['admin', 'language', 'user', 'permission', 'role'];
    private $overwriteExistingTranslation;
    private $showSingleTargetLanguageButtons;
    private $translatedFieldsCount = 0;

    private $textFieldTypes = [
        'PageTitleLanguage',
        'TextLanguage',
        'TextareaLanguage',
    ];

    private $descriptionFieldTypes = [
        'File',
        'Image',
    ];

    private $repeaterFieldTypes = [
        'Repeater',
        'RepeaterMatrix',
    ];

    public function init() {
        if (!$this->user->hasPermission('fluency-translate')) {
            return;
        }

        $this->initSettings();
        $this->addHookAfter("ProcessPageEdit::getSubmitActions", $this, "addDropdownOption");
        $this->addHookAfter("Pages::saved", $this, "hookPageSave");

        parent::init();
    }

    public function initSettings() {
        // Set (user-)settings
        $this->excludedTemplates = $this->get('excludedTemplates');
        $this->excludedFields = $this->get('excludedFields');
        $this->excludedLanguages = $this->get('excludedLanguages');
        $this->overwriteExistingTranslation = !!$this->get('overwriteExistingTranslation');
        $this->showSingleTargetLanguageButtons = !!$this->get('showSingleTargetLanguageButtons');
        $this->throttleSave = 5;

        $this->fluency = $this->modules->get('Fluency');
        $this->setLanguages();
    }

    public function hookPageSave($event) {
        /** @var Page $page */
        $page = $event->arguments("page");

        // Only start translating if post variable is set
        if (strpos($this->input->post->_after_submit_action, 'save_and_translate') !== 0) {
            return;
        }

        // Check if post variable has an appended single target language code
        if ($this->input->post->_after_submit_action !== 'save_and_translate') {

            // Selected target language is the last part of the post variable
            $singleTargetLanguage = str_replace('save_and_translate_', '', $this->input->post->_after_submit_action);

            // Filter all allowed target languages for the selected language name
            $this->targetLanguages = array_filter($this->targetLanguages, function ($targetLanguage) use ($singleTargetLanguage) {
                return $targetLanguage['page']->name === $singleTargetLanguage;
            });
        }

        // Throttle translations (only triggers every after a set amount of time)
        if ($this->page->modified > (time() - $this->throttleSave)) {
            $this->error(__('Please wait some time before you try to translate again.'));

            return;
        }

        // Let’s go!
        $this->processFields($page);
        $this->message($this->translatedFieldsCount.' '.__('fields translated.'));
    }

    public function addDropdownOption($event) {
        /** @var Page $page */
        $page = $this->pages->get($this->input->get->id);

        // Don’t show option in excluded or admin templates
        if (in_array($page->template->name, array_merge($this->adminTemplates, $this->excludedTemplates))) {
            return;
        }

        $actions = $event->return;

        // If single buttons are set, add one button for each target language
        if ($this->showSingleTargetLanguageButtons) {
            foreach ($this->targetLanguages as $targetLanguage) {
                $label = __('Save + Translate to');
                $actions[] = [
                    'value' => 'save_and_translate_' . $targetLanguage['page']->name,
                    'icon' => 'language',
                    'label' => $label . ' ' . $targetLanguage['page']->get('title|name'),
                ];
            }
        // Else add only one button to translate to all target languages
        } else {
            $actions[] = [
                'value' => 'save_and_translate',
                'icon' => 'language',
                'label' => __('Save + Translate'),
            ];
        }

        $event->return = $actions;
    }

    public function translatePageTree(Page $page, bool $includeHidden = true) {
        $this->initSettings();
        // Only process page if template is valid
        if (!in_array($page->template->name, array_merge($this->adminTemplates, $this->excludedTemplates))) {
            $this->processFields($page);
            echo "Process page {$page->title} ($page->id)\n";
        } else {
            echo "Ignore page {$page->title} ($page->id)\n";
        }

        $selector = ($includeHidden) ? 'include=hidden' : '';

        // Iterate through all children and process them recursively
        foreach ($page->children($selector) as $item) {
            $this->translatePageTree($item);
        }
    }

    private function setLanguages() {
        $defaultLanguage = $this->languages->get('default');
        $defaultLanguageId = $defaultLanguage->id;
        if (!$this->fluency->data['pw_language_'.$defaultLanguageId]) {
            $this->error(__('Fluency is not setup correctly. Please set source and target language(s) first.'));
        }
        $this->sourceLanguage = [
            'page' => $defaultLanguage,
            'code' => $this->fluency->data['pw_language_'.$defaultLanguageId],
        ];

        foreach ($this->fluency->data as $key => $data) {
            // Ignore non language keys and default language key
            if (strpos($key, 'pw_language_') !== 0 || $key === 'pw_language_1022') {
                continue;
            }

            $languagePage = $this->languages->get(str_replace('pw_language_', '', $key));

            // Ignore languages which are set as excluded in user settings
            if (in_array($languagePage->name, $this->excludedLanguages)) {
                continue;
            }

            $this->targetLanguages[] = [
                'page' => $languagePage,
                'code' => $data,
            ];
        }
    }

    private function translate(string $value, string $targetLanguageCode): string {
        if (!$targetLanguageCode) {
            return '';
        }
        $result = $this->fluency->translate($this->sourceLanguage['code'], $value, $targetLanguageCode);
        $resultText = $result->data->translations[0]->text;

        return $resultText;
    }

    private function processFields($page) {
        $page->of(false);
        $fields = $page->template->fields;

        if (get_class($page) === 'ProcessWire\RepeaterMatrixPage') {
            $fields = $page->matrix('fields');
        }

        foreach ($fields as $field) {
            // Ignore fields that are set as excluded in user settings
            if (in_array($field->name, $this->excludedFields)) {
                continue;
            }

            // e.g. Processwire/FieldtypePageTitleLanguage -> PageTitleLanguage
            $shortType = str_replace('ProcessWire/', '', $field->type);
            $shortType = str_replace('Fieldtype', '', $shortType);

            if (in_array($shortType, $this->textFieldTypes)) {
                $this->processTextField($field, $page);
                continue;
            }

            if (in_array($shortType, $this->descriptionFieldTypes)) {
                $this->processFileField($field, $page);
                continue;
            }

            if (in_array($shortType, $this->repeaterFieldTypes)) {
                $this->processRepeaterField($field, $page);
                continue;
            }

            if ($shortType == 'FieldsetPage') {
                $this->processFieldsetPage($field, $page);
                continue;
            }

            if ($shortType == 'Functional') {
                $this->processFunctionalField($field, $page);
                continue;
            }

            if ($shortType == 'Table') {
                $this->processTableField($field, $page);
                continue;
            }
        }
    }

    private function processTextField(Field $field, Page $page) {
        $fieldName = $field->name;
        $value = $page->getLanguageValue($this->sourceLanguage['page'], $fieldName);
        $countField = false;

        foreach ($this->targetLanguages as $targetLanguage) {
            // If field is empty or translation already exists und should not be overwritten, return
            if (!$value || ($page->getLanguageValue($targetLanguage['page'], $fieldName) != '' && !$this->overwriteExistingTranslation)) {
                continue;
            }
            $result = $this->translate($value, $targetLanguage['code']);
            $page->setLanguageValue($targetLanguage['page'], $fieldName, $result);
            $countField = true;
        }

        $page->save($fieldName);
        if ($countField) {
            $this->translatedFieldsCount++;
        }
    }

    private function processFileField(Field $field, Page $page) {
        /** @var Field $field */
        $field = $page->$field;
        if (!$field->count()) {
            return;
        }
        $countField = false;

        foreach ($field as $item) {
            $value = $item->description;

            foreach ($this->targetLanguages as $targetLanguage) {
                // If no description set or translated description already exists und should not be overwritten, continue
                if (!$value || ($item->description($targetLanguage['page']) != '' && !$this->overwriteExistingTranslation)) {
                    continue;
                }
                $result = $this->translate($value, $targetLanguage['code']);
                $item->description($targetLanguage['page'], $result);
                $countField = true;
            }
            $item->save();
            if ($countField) {
                $this->translatedFieldsCount++;
            }
        }
    }

    private function processRepeaterField(RepeaterField $field, Page $page) {
        foreach ($page->$field as $item) {
            $this->processFields($item);
        }
    }

    private function processFieldsetPage(Field $field, Page $page) {
        $this->processFields($page->$field);
    }

    private function processFunctionalField(Field $field, Page $page) {
        foreach ($page->$field as $name => $value) {
            // Ignore fallback values (starting with a dot)
            if (strpos($name, '.') === 0) {
                continue;
            }
            $countField = false;

            foreach ($this->targetLanguages as $targetLanguage) {
                $targetFieldName = $name.'.'.$targetLanguage['page']->id;
                // If translation already exists und should not be overwritten, continue
                if ($page->$field->$targetFieldName != '' && !$this->overwriteExistingTranslation) {
                    continue;
                }
                $result = $this->translate($value, $targetLanguage['code']);
                $page->$field->$targetFieldName = $result;
                $countField = true;
            }
            if ($countField) {
                $this->translatedFieldsCount++;
            }
        }
        $page->save($field->name);
    }

    private function processTableField(Field $field, Page $page) {
        $fieldName = $field->name;

        foreach ($page->$field as $row) {
            /** @var TableRow $row */
            foreach ($row as $item) {
                if ($item instanceof LanguagesPageFieldValue) {
                    /** @var LanguagesPageFieldValue $item */
                    $value = $item->getLanguageValue($this->sourceLanguage['page']);
                    $countField = false;

                    foreach ($this->targetLanguages as $targetLanguage) {
                        // If field is empty or translation already exists und should not be overwritten, return
                        if (!$value || ($item->getLanguageValue($targetLanguage['page']) != '' && !$this->overwriteExistingTranslation)) {
                            continue;
                        }
                        $result = $this->translate($value, $targetLanguage['code']);
                        $item->setLanguageValue($targetLanguage['page'], $result);
                        $countField = true;
                    }

                    if ($countField) {
                        $this->translatedFieldsCount++;
                    }
                }
            }
        }
        $page->save($fieldName);
    }
}
