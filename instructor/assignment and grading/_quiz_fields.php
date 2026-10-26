<?php
/* Shared quiz fields. Needs $quizValues, $quizPostedQuestions and assessmentFormEscape(). */
?>
                <div class="ins-lesson-field">
                    <label for="quizTitle">Quiz title</label>
                    <input
                        type="text"
                        id="quizTitle"
                        name="quiz_title"
                        maxlength="190"
                        required
                        value="<?= assessmentFormEscape($quizValues['title']) ?>"
                    >
                </div>

                <div class="ins-lesson-field">
                    <label for="quizDescription">Description (optional)</label>
                    <textarea
                        id="quizDescription"
                        name="quiz_description"
                        maxlength="5000"
                    ><?= assessmentFormEscape($quizValues['description']) ?></textarea>
                </div>

                <div class="ins-quiz-row">
                    <div class="ins-lesson-field">
                        <label for="quizPassMark">Pass mark (%)</label>
                        <input
                            type="number"
                            id="quizPassMark"
                            name="quiz_pass_mark"
                            min="1"
                            max="100"
                            step="1"
                            required
                            value="<?= assessmentFormEscape($quizValues['pass_mark']) ?>"
                        >
                    </div>

                    <div class="ins-lesson-field">
                        <label for="quizMaxAttempts">Maximum attempts</label>
                        <input
                            type="number"
                            id="quizMaxAttempts"
                            name="quiz_max_attempts"
                            min="1"
                            max="20"
                            step="1"
                            required
                            value="<?= assessmentFormEscape($quizValues['max_attempts']) ?>"
                        >
                    </div>
                </div>

                <div class="ins-quiz-row">
                    <div class="ins-lesson-field">
                        <label for="quizStartTime">Start time (optional)</label>
                        <input
                            type="datetime-local"
                            id="quizStartTime"
                            name="quiz_start_time"
                            value="<?= assessmentFormEscape($quizValues['start_time']) ?>"
                        >
                    </div>

                    <div class="ins-lesson-field">
                        <label for="quizEndTime">End time (optional)</label>
                        <input
                            type="datetime-local"
                            id="quizEndTime"
                            name="quiz_end_time"
                            value="<?= assessmentFormEscape($quizValues['end_time']) ?>"
                        >
                    </div>
                </div>
                <p class="ins-quiz-hint">Students can only take the quiz between these times. Leave a field empty for no limit.</p>

                <fieldset class="ins-quiz-method">
                    <legend>How do you want to add the questions?</legend>

                    <label class="ins-quiz-method__option">
                        <input
                            type="radio"
                            name="quiz_method"
                            value="manual"
                            <?= $quizValues['method'] === 'manual' ? 'checked' : '' ?>
                        >
                        <span>Add questions manually</span>
                    </label>

                    <label class="ins-quiz-method__option">
                        <input
                            type="radio"
                            name="quiz_method"
                            value="csv"
                            <?= $quizValues['method'] === 'csv' ? 'checked' : '' ?>
                        >
                        <span>Upload a CSV file</span>
                    </label>
                </fieldset>

                <!-- Method 1: CSV upload -->
                <div class="ins-lesson-form" data-quiz-method-panel="csv" hidden>
                    <div class="ins-lesson-field">
                        <label for="quizCsv">Questions file (.csv)</label>
                        <input type="file" id="quizCsv" name="quiz_csv" accept=".csv,text/csv">
                        <small>
                            Up to <?= (int) QUIZ_MAX_QUESTIONS ?> questions, 1 MB.
                            <a href="<?= assessmentFormEscape(url('assests/templates/quiz_template.csv')) ?>" download>Download the template</a>
                        </small>
                        <p class="ins-lesson-message" id="quizCsvMessage" role="status" aria-live="polite"></p>
                    </div>

                    <div class="ins-assess-note">
                        <strong>CSV columns:</strong>
                        <code>type, question, marks, option1, option2, option3, option4, option5, correct, model_answer</code><br>
                        <code>type</code> is <code>mcq</code> or <code>essay</code>.
                        MCQ rows need option1 to option4 (option5 is optional) and
                        <code>correct</code> as the option number (1 to 5) or letter (A to E).
                        Essay rows leave the options and <code>correct</code> empty;
                        <code>model_answer</code> is optional.
                    </div>
                </div>

                <!-- Method 2: manual entry -->
                <div class="ins-lesson-form" data-quiz-method-panel="manual" hidden>
                    <div id="quizQuestions" class="ins-quiz-questions"></div>

                    <p class="ins-assess-note" id="quizEmptyNote">
                        No questions yet. Add a multiple choice or an essay question.
                    </p>

                    <div class="ins-quiz-add">
                        <button type="button" class="ins-btn" id="quizAddMcq">+ Add MCQ question</button>
                        <button type="button" class="ins-btn" id="quizAddEssay">+ Add essay question</button>
                    </div>

                    <p class="ins-quiz-total" id="quizTotals" aria-live="polite"></p>
                </div>

                <script type="application/json" id="quizInitialQuestions"><?=
                    json_encode(
                        $quizPostedQuestions,
                        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
                    ) ?: '[]'
                ?></script>
