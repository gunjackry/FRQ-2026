(function () {
  const app = document.getElementById('frq-p1-v1-app');
  const shareViewApp = document.getElementById('frq-p1-v1-share-view');

  if (shareViewApp) {
    renderSharedView();
    return;
  }

  if (!app) return;

  const treeCanvasEl = document.getElementById('frq-tree-canvas');
  const editorPaneEl = document.getElementById('frq-editor-pane');
  const statusEl = document.getElementById('frq-status');
  const noticeEl = document.getElementById('frq-generation-notice');
  const contactNameEl = document.getElementById('frq-contact-name');
  const emailEl = document.getElementById('frq-guest-email');
  const consentEl = document.getElementById('frq-consent');
  const honeyEl = document.getElementById('frq-company');
  const shareEmailsEl = document.getElementById('frq-share-emails');
  const shareBtn = document.getElementById('frq-share');
  const questFillEl = document.getElementById('frq-quest-fill');
  const questPercentEl = document.getElementById('frq-quest-percent');
  const questCopyEl = document.getElementById('frq-quest-copy');
  const questMilestonesEl = document.getElementById('frq-milestones');
  const submitBtn = document.getElementById('frq-submit');
  const scoreValueEl = document.getElementById('frq-score-value');
  const scoreCardBtn = document.getElementById('frq-score-card-btn');
  const scoreTipEl = document.getElementById('frq-score-tip');

  let lastSubmissionToken = null;
  const people = [];
  let nextId = 1;
  let selectedPersonId = null;

  const RELATION_OPTIONS = [
    ['applicant', 'Applicant'], ['father', 'Father'], ['mother', 'Mother'],
    ['paternal_grandfather', 'Paternal Grandfather'], ['paternal_grandmother', 'Paternal Grandmother'],
    ['maternal_grandfather', 'Maternal Grandfather'], ['maternal_grandmother', 'Maternal Grandmother'],
    ['great_paternal_grandfather', 'Great Paternal Grandfather'],
    ['son', 'Son'], ['daughter', 'Daughter'],
    ['step_father', 'Step Father'], ['step_mother', 'Step Mother'],
    ['adopted_parent', 'Adopted Parent'], ['legal_guardian', 'Legal Guardian'],
    ['step_son', 'Step Son'], ['step_daughter', 'Step Daughter'],
    ['adopted_son', 'Adopted Son'], ['adopted_daughter', 'Adopted Daughter'],
    ['other', 'Other'],
  ];

  const ADD_ABOVE_OPTIONS = [
    ['step_father', 'Step Father'],
    ['step_mother', 'Step Mother'],
    ['adopted_parent', 'Adopted Parent'],
    ['legal_guardian', 'Legal Guardian'],
  ];

  const ADD_BELOW_OPTIONS = [
    ['son', 'Son'],
    ['daughter', 'Daughter'],
    ['step_son', 'Step Son'],
    ['step_daughter', 'Step Daughter'],
    ['adopted_son', 'Adopted Son'],
    ['adopted_daughter', 'Adopted Daughter'],
  ];

  const PRIMARY_TREE_ORDER = [
    'paternal_grandfather', 'paternal_grandmother', 'maternal_grandfather', 'maternal_grandmother',
    'father', 'mother', 'applicant',
  ];

  function relationLabel(value) {
    const found = RELATION_OPTIONS.find(([key]) => key === value);
    return found ? found[1] : 'Other';
  }

  function makePerson(generation, relation = 'other') {
    return {
      id: nextId++,
      generation,
      relation,
      name: '',
      dob: '',
      pob: '',
      occupation: '',
      marital_status: '',
      crown_service: 'unknown',
      research_branch: false,
      show_occupation: false,
      show_marital_status: false,
      show_crown_service: false,
    };
  }

  function getPersonById(id) {
    return people.find((person) => person.id === id) || null;
  }

  function unlockScore(person) {
    let score = 0;
    if ((person.name || '').trim()) score += 1;
    if ((person.dob || '').trim()) score += 1;
    if ((person.pob || '').trim()) score += 1;
    return score;
  }

  function statusBadge(person) {
    const score = unlockScore(person);
    if (score === 3) return '3/3';
    if (score === 0) return 'Unknown';
    return `${score}/3`;
  }

  function nodeButton(person) {
    const selected = person.id === selectedPersonId ? 'is-selected' : '';
    return `<button type="button" class="frq-node ${selected}" data-person-id="${person.id}">
      <span class="frq-node__title">${relationLabel(person.relation)}</span>
      <span class="frq-node__status">${statusBadge(person)}</span>
    </button>`;
  }

  function renderTree() {
    const byRelation = {};
    people.forEach((person) => {
      if (!byRelation[person.relation]) byRelation[person.relation] = person;
    });

    const topRow = ['paternal_grandfather', 'paternal_grandmother', 'maternal_grandfather', 'maternal_grandmother']
      .map((r) => byRelation[r]).filter(Boolean).map(nodeButton).join('');
    const midRow = ['father', 'mother'].map((r) => byRelation[r]).filter(Boolean).map(nodeButton).join('');
    const bottomRow = ['applicant'].map((r) => byRelation[r]).filter(Boolean).map(nodeButton).join('');

    const extras = people
      .filter((person) => !PRIMARY_TREE_ORDER.includes(person.relation) || byRelation[person.relation]?.id !== person.id)
      .map(nodeButton)
      .join('');

    treeCanvasEl.innerHTML = `
      <div class="frq-tree-row frq-tree-row--top">${topRow}</div>
      <div class="frq-tree-connectors"></div>
      <div class="frq-tree-row frq-tree-row--mid">${midRow}</div>
      <div class="frq-tree-connectors frq-tree-connectors--short"></div>
      <div class="frq-tree-row frq-tree-row--bottom">${bottomRow}</div>
      ${extras ? `<div class="frq-tree-extra"><h4>Additional Members</h4><div class="frq-tree-extra-grid">${extras}</div></div>` : ''}
    `;

    treeCanvasEl.querySelectorAll('[data-person-id]').forEach((btn) => {
      btn.addEventListener('click', () => {
        selectedPersonId = Number(btn.getAttribute('data-person-id'));
        render();
      });
    });
  }

  function renderEditor() {
    const person = getPersonById(selectedPersonId);
    if (!person) {
      editorPaneEl.innerHTML = '<p>Select a node from the tree to begin.</p>';
      return;
    }

    editorPaneEl.innerHTML = `
      <div class="frq-editor-card">
        <h4>${relationLabel(person.relation)} details</h4>
        <p class="frq-help">Unknown is the default. Every field left blank is treated as unknown. Unlock this node by adding what you know.</p>
        <p class="frq-unlock-score">Unlock score: <strong>${unlockScore(person)}/3 known</strong></p>

        <label>Name <input type="text" data-field="name" placeholder="Unknown" value="${person.name || ''}" /></label>
        <label>Date of Birth <input type="text" data-field="dob" placeholder="Unknown" value="${person.dob || ''}" /></label>
        <label>Place of Birth <input type="text" data-field="pob" placeholder="Unknown" value="${person.pob || ''}" /></label>

        <div class="frq-editor-section">
          <h5>Section 1: Add optional details</h5>
          <div class="frq-chip-actions">
            <button type="button" data-toggle="occupation">Registered occupation at time of child's birth</button>
            <button type="button" data-toggle="marital_status">Marital status</button>
            <button type="button" data-toggle="crown_service">Crown Service</button>
          </div>
          <div class="frq-reveal ${person.show_occupation ? 'is-visible' : ''}" data-reveal="occupation">
            <label>Registered occupation at time of child's birth <input type="text" data-field="occupation" placeholder="Unknown" value="${person.occupation || ''}" /></label>
          </div>
          <div class="frq-reveal ${person.show_marital_status ? 'is-visible' : ''}" data-reveal="marital_status">
            <label>Marital status <input type="text" data-field="marital_status" placeholder="Unknown" value="${person.marital_status || ''}" /></label>
          </div>
          <div class="frq-reveal ${person.show_crown_service ? 'is-visible' : ''}" data-reveal="crown_service">
            <label>Crown Service
              <select data-field="crown_service">
                <option value="unknown" ${person.crown_service === 'unknown' ? 'selected' : ''}>Unknown</option>
                <option value="yes" ${person.crown_service === 'yes' ? 'selected' : ''}>Yes</option>
                <option value="no" ${person.crown_service === 'no' ? 'selected' : ''}>No</option>
              </select>
            </label>
          </div>
        </div>

        <div class="frq-editor-section">
          <h5>Section 2: Add tree links</h5>
          <div class="frq-chip-actions">
            <button type="button" data-action="add-link-above">Add tree link above</button>
            <button type="button" data-action="add-link-below">Add tree link below</button>
          </div>
          <p class="frq-help">Above: step parents, adopted parents, legal guardians. Below: son/daughter and step/adopted child links.</p>
        </div>

        <label class="frq-checkbox"><input type="checkbox" data-field="research_branch" ${person.research_branch ? 'checked' : ''}/> Request branch research for unknowns</label>
      </div>
    `;

    editorPaneEl.querySelectorAll('[data-field]').forEach((input) => {
      input.addEventListener('input', (e) => {
        const field = e.target.getAttribute('data-field');
        person[field] = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
        render();
      });
    });

    editorPaneEl.querySelectorAll('[data-toggle]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const key = btn.getAttribute('data-toggle');
        if (key === 'occupation') person.show_occupation = true;
        if (key === 'marital_status') person.show_marital_status = true;
        if (key === 'crown_service') person.show_crown_service = true;
        renderEditor();
      });
    });

    editorPaneEl.querySelector('[data-action="add-link-above"]').addEventListener('click', () => {
      const chosen = pickRelation('Add tree link above', ADD_ABOVE_OPTIONS);
      if (!chosen) return;
      const newPerson = makePerson(Math.max(1, person.generation + 1), chosen);
      people.push(newPerson);
      selectedPersonId = newPerson.id;
      render();
    });

    editorPaneEl.querySelector('[data-action="add-link-below"]').addEventListener('click', () => {
      const chosen = pickRelation('Add tree link below', ADD_BELOW_OPTIONS);
      if (!chosen) return;
      const newPerson = makePerson(Math.max(0, person.generation - 1), chosen);
      people.push(newPerson);
      selectedPersonId = newPerson.id;
      render();
    });
  }

  function pickRelation(title, options) {
    const labels = options.map(([, label], idx) => `${idx + 1}. ${label}`).join('\n');
    const input = window.prompt(`${title}\n${labels}\n\nEnter number:`);
    const idx = Number(input) - 1;
    if (Number.isNaN(idx) || idx < 0 || idx >= options.length) return null;
    return options[idx][0];
  }

  function updateGenerationNotice() {
    const hasGeneration4 = people.some((person) => person.generation >= 4);
    noticeEl.textContent = hasGeneration4
      ? 'Generation 4 is active. Great for deeper checks such as Crown Service and historic branch research.'
      : 'Click a node on the left to unlock details in the panel on the right.';
  }

  function familyQuestStats() {
    const totalFields = people.length * 3;
    const knownFields = people.reduce((sum, person) => sum + unlockScore(person), 0);
    const percent = totalFields > 0 ? Math.round((knownFields / totalFields) * 100) : 0;
    const coreKnown = people
      .filter((person) => PRIMARY_TREE_ORDER.includes(person.relation))
      .filter((person) => unlockScore(person) >= 2).length;

    return { totalFields, knownFields, percent, coreKnown };
  }

  function renderMilestones(stats) {
    if (!questMilestonesEl) return;
    const milestones = [
      { label: 'First clue', done: stats.knownFields >= 1 },
      { label: '10 fields known', done: stats.knownFields >= 10 },
      { label: 'Core tree mapped', done: stats.coreKnown >= 4 },
      { label: 'Ready to submit', done: stats.percent >= 60 },
    ];

    questMilestonesEl.innerHTML = milestones.map((item) => `
      <span class="frq-milestone ${item.done ? 'is-done' : ''}">${item.done ? '✓' : '○'} ${item.label}</span>
    `).join('');
  }

  function updateQuestProgress() {
    const stats = familyQuestStats();
    if (questFillEl) {
      questFillEl.style.width = `${stats.percent}%`;
      const bar = questFillEl.parentElement;
      if (bar) bar.setAttribute('aria-valuenow', String(stats.percent));
    }
    if (questPercentEl) questPercentEl.textContent = `${stats.percent}%`;
    if (questCopyEl) {
      questCopyEl.textContent = stats.percent >= 60
        ? 'Great progress. You are ready to submit Phase 1.'
        : `You have unlocked ${stats.knownFields} of ${stats.totalFields} key clues.`;
    }
    if (submitBtn) {
      submitBtn.textContent = stats.percent >= 60 ? 'Submit Phase 1 ✓ Ready' : 'Submit Phase 1';
    }
    renderMilestones(stats);
  }

  async function refreshScoreCard() {
    if (!lastSubmissionToken || !scoreValueEl) return;

    const response = await fetch(`${frqP1V1Config.restUrl}/score?submission_token=${encodeURIComponent(lastSubmissionToken)}`, {
      method: 'GET',
      headers: { 'X-FRQ-Nonce': frqP1V1Config.submitNonce },
    });

    const data = await response.json();
    if (!response.ok || !data.success) return;

    const score = Number(data?.score?.total || 0);
    scoreValueEl.textContent = `${score}`;
    if (scoreTipEl) {
      scoreTipEl.textContent = `Earn more points: +10 per known field, +15 per share, +5 per share open.`;
    }
  }

  function render() {
    renderTree();
    renderEditor();
    updateGenerationNotice();
    updateQuestProgress();
  }

  function seedThreeGenerations() {
    people.length = 0;
    people.push(makePerson(1, 'applicant'));
    people.push(makePerson(2, 'father'));
    people.push(makePerson(2, 'mother'));
    people.push(makePerson(3, 'paternal_grandfather'));
    people.push(makePerson(3, 'paternal_grandmother'));
    people.push(makePerson(3, 'maternal_grandfather'));
    people.push(makePerson(3, 'maternal_grandmother'));
    selectedPersonId = people[0].id;
    render();
  }

  async function send(status) {
    if (!contactNameEl.value.trim() || !emailEl.value.trim()) {
      statusEl.textContent = status === 'submitted'
        ? 'Please add your contact name and email before submitting.'
        : 'Please add your contact name and email before saving.';
      statusEl.classList.add('error');
      return;
    }

    if (status === 'submitted') {
      if (!consentEl.checked) {
        statusEl.textContent = 'Please confirm consent before submitting.';
        statusEl.classList.add('error');
        return;
      }
    }

    statusEl.classList.remove('error');
    statusEl.textContent = 'Saving...';

    const response = await fetch(`${frqP1V1Config.restUrl}/submission`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-FRQ-Nonce': frqP1V1Config.submitNonce },
      body: JSON.stringify({ people, status, consent: consentEl.checked, contact_name: contactNameEl.value, company: honeyEl.value, guest_email: emailEl.value }),
    });

    const data = await response.json();
    if (!response.ok || !data.success) {
      statusEl.textContent = data.message || 'Unable to save.';
      statusEl.classList.add('error');
      return;
    }

    lastSubmissionToken = data.submission_token;
    if (Number.isFinite(Number(data.score))) {
      scoreValueEl.textContent = `${Number(data.score)}`;
    }
    statusEl.classList.remove('error');
    statusEl.textContent = data.message;
    refreshScoreCard();
  }

  async function shareReadOnlyCopy() {
    if (!lastSubmissionToken) {
      statusEl.textContent = 'Please save or submit first before sharing.';
      statusEl.classList.add('error');
      return;
    }

    const emails = (shareEmailsEl.value || '').split(',').map((e) => e.trim()).filter(Boolean);
    if (!emails.length) {
      statusEl.textContent = 'Please add at least one recipient email.';
      statusEl.classList.add('error');
      return;
    }

    const message = window.prompt('Optional message for recipients:', '') || '';

    const response = await fetch(`${frqP1V1Config.restUrl}/shares`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-FRQ-Nonce': frqP1V1Config.submitNonce },
      body: JSON.stringify({
        submission_token: lastSubmissionToken,
        sender_email: emailEl.value,
        recipients: emails.map((email) => ({ email })),
        message,
        expires_in_days: 30,
        consent_to_share: true,
      }),
    });

    const data = await response.json();
    if (!response.ok || !data.success) {
      statusEl.textContent = data.message || 'Unable to share right now.';
      statusEl.classList.add('error');
      return;
    }

    statusEl.classList.remove('error');
    statusEl.textContent = `Shared with ${data.shares.length} recipient(s).`;
    refreshScoreCard();
  }

  async function renderSharedView() {
    const container = document.getElementById('frq-share-content');
    if (!container) return;

    const params = new URLSearchParams(window.location.search);
    const token = params.get('t');
    if (!token) {
      container.innerHTML = '<p>Missing share token.</p>';
      return;
    }

    const response = await fetch(`${frqP1V1Config.restUrl}/shares/view?token=${encodeURIComponent(token)}`);
    const data = await response.json();

    if (!response.ok || !data.success) {
      container.innerHTML = `<p>${data.message || 'Unable to load shared declaration.'}</p>`;
      return;
    }

    const people = (data.tree && data.tree.people) || [];
    const list = people.map((p) => `<li><strong>${relationLabel(p.relation || 'other')}</strong>: ${p.name || 'Unknown'} ${p.dob ? `(DOB: ${p.dob})` : '(DOB: Unknown)'} ${p.pob ? `(POB: ${p.pob})` : '(POB: Unknown)'}</li>`).join('');

    container.innerHTML = `
      <p>This is a read-only copy shared with you.</p>
      <ul>${list}</ul>
      <p><a href="${data.cta.create_own_tree_url}">Create my own tree</a></p>
    `;
  }

  document.getElementById('frq-save-draft').addEventListener('click', () => send('draft'));
  document.getElementById('frq-submit').addEventListener('click', () => send('submitted'));
  if (shareBtn) shareBtn.addEventListener('click', shareReadOnlyCopy);
  if (scoreCardBtn) {
    scoreCardBtn.addEventListener('click', async () => {
      if (!lastSubmissionToken) {
        statusEl.textContent = 'Save your progress first to unlock score sharing.';
        statusEl.classList.add('error');
        return;
      }
      await refreshScoreCard();
      shareEmailsEl.focus();
      statusEl.classList.remove('error');
      statusEl.textContent = 'Tip: Share your tree with family/friends to earn more points.';
    });
  }

  seedThreeGenerations();
})();
