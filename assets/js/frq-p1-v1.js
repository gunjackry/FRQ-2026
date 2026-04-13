(function () {
  const app = document.getElementById('frq-p1-v1-app');
  const shareViewApp = document.getElementById('frq-p1-v1-share-view');

  if (shareViewApp) {
    renderSharedView();
    return;
  }

  if (!app) return;

  const personList = document.getElementById('frq-person-list');
  const statusEl = document.getElementById('frq-status');
  const noticeEl = document.getElementById('frq-generation-notice');
  const contactNameEl = document.getElementById('frq-contact-name');
  const emailEl = document.getElementById('frq-guest-email');
  const consentEl = document.getElementById('frq-consent');
  const honeyEl = document.getElementById('frq-company');
  const shareEmailsEl = document.getElementById('frq-share-emails');
  const shareBtn = document.getElementById('frq-share');

  let lastSubmissionToken = null;
  const people = [];
  let nextId = 1;

  const RELATION_OPTIONS = [
    ['applicant', 'Applicant'], ['father', 'Father'], ['mother', 'Mother'],
    ['paternal_grandfather', 'Paternal Grandfather'], ['paternal_grandmother', 'Paternal Grandmother'],
    ['maternal_grandfather', 'Maternal Grandfather'], ['maternal_grandmother', 'Maternal Grandmother'],
    ['great_paternal_grandfather', 'Great Paternal Grandfather'], ['son', 'Son'], ['daughter', 'Daughter'], ['other', 'Other'],
  ];

  function generationLabel(generation) {
    if (generation === 1) return 'Generation 1 (Self / Root)';
    if (generation === 2) return 'Generation 2 (Parents Level)';
    if (generation === 3) return 'Generation 3 (Grandparents Level)';
    if (generation === 4) return 'Generation 4 (Great-grandparents / deeper records)';
    if (generation < 1) return 'Minor Generation';
    return `Generation ${generation}`;
  }

  function relationOptionsHtml(value) {
    return RELATION_OPTIONS.map(([val, label]) => `<option value="${val}" ${value === val ? 'selected' : ''}>${label}</option>`).join('');
  }

  function personTemplate(person, index) {
    return `
      <div class="frq-person" data-index="${index}">
        <h3>${generationLabel(person.generation)} — Family Member ${index + 1}</h3>
        <label>Relationship
          <select data-field="relation">${relationOptionsHtml(person.relation)}</select>
        </label>
        <label>Name <input type="text" data-field="name" value="${person.name || ''}" /></label>
        <label>DOB <input type="text" placeholder="YYYY-MM-DD or approximate" data-field="dob" value="${person.dob || ''}" /></label>
        <label>POB <input type="text" data-field="pob" value="${person.pob || ''}" /></label>
        <label>Occupation <input type="text" data-field="occupation" value="${person.occupation || ''}" /></label>
        <label>Marital Status <input type="text" data-field="marital_status" value="${person.marital_status || ''}" /></label>
        <label>Crown Service
          <select data-field="crown_service">
            <option value="unknown" ${person.crown_service === 'unknown' ? 'selected' : ''}>Unknown</option>
            <option value="yes" ${person.crown_service === 'yes' ? 'selected' : ''}>Yes</option>
            <option value="no" ${person.crown_service === 'no' ? 'selected' : ''}>No</option>
          </select>
        </label>
        <label class="frq-checkbox">
          <input type="checkbox" data-field="research_branch" ${person.research_branch ? 'checked' : ''} /> Request branch research for unknowns
        </label>
        <div class="frq-person__actions">
          <button type="button" data-action="add-above">+ Add generation above</button>
          <button type="button" data-action="add-below">+ Add generation below (minors)</button>
        </div>
      </div>`;
  }

  function makePerson(generation, relation = 'other') {
    return { id: nextId++, generation, relation, name: '', dob: '', pob: '', occupation: '', marital_status: '', crown_service: 'unknown', research_branch: false };
  }

  function render() {
    personList.innerHTML = people.map((person, index) => personTemplate(person, index)).join('');

    personList.querySelectorAll('[data-field]').forEach((input) => {
      input.addEventListener('input', (e) => {
        const card = e.target.closest('.frq-person');
        const index = Number(card.getAttribute('data-index'));
        const field = e.target.getAttribute('data-field');
        people[index][field] = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
      });
    });

    personList.querySelectorAll('[data-action="add-above"]').forEach((btn) => btn.addEventListener('click', (e) => {
      const index = Number(e.target.closest('.frq-person').getAttribute('data-index'));
      people.splice(index + 1, 0, makePerson(Math.max(1, people[index].generation + 1)));
      render();
    }));

    personList.querySelectorAll('[data-action="add-below"]').forEach((btn) => btn.addEventListener('click', (e) => {
      const index = Number(e.target.closest('.frq-person').getAttribute('data-index'));
      people.splice(index + 1, 0, makePerson(Math.max(0, people[index].generation - 1)));
      render();
    }));

    updateGenerationNotice();
  }

  function updateGenerationNotice() {
    const hasGeneration3 = people.some((person) => person.generation === 3);
    const hasGeneration4 = people.some((person) => person.generation >= 4);
    if (hasGeneration3 && !hasGeneration4) {
      noticeEl.textContent = 'You have opened Generation 3. You can now add Generation 4 to explore deeper records like Crown Service.';
      return;
    }
    if (hasGeneration4) {
      noticeEl.textContent = 'Generation 4 is active. Great for deeper checks such as Crown Service and historic branch research.';
      return;
    }
    noticeEl.textContent = 'Tip: Start with 3 generations, then add above or below to expand your tree.';
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
    render();
  }

  async function send(status) {
    if (status === 'submitted') {
      if (!contactNameEl.value.trim() || !emailEl.value.trim()) {
        statusEl.textContent = 'Please add your contact name and email before submitting.';
        statusEl.classList.add('error');
        return;
      }
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
    statusEl.classList.remove('error');
    statusEl.textContent = `${data.message} Share token: ${data.share_token}`;
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
    const list = people.map((p) => `<li><strong>${p.relation || 'other'}</strong>: ${p.name || 'Unknown'} ${p.dob ? `(DOB: ${p.dob})` : ''} ${p.pob ? `(POB: ${p.pob})` : ''}</li>`).join('');

    container.innerHTML = `
      <p>This is a read-only copy shared with you.</p>
      <ul>${list}</ul>
      <p><a href="${data.cta.create_own_tree_url}">Create my own tree</a></p>
    `;
  }

  document.getElementById('frq-add-root').addEventListener('click', () => { people.push(makePerson(1)); render(); });
  document.getElementById('frq-save-draft').addEventListener('click', () => send('draft'));
  document.getElementById('frq-submit').addEventListener('click', () => send('submitted'));
  if (shareBtn) shareBtn.addEventListener('click', shareReadOnlyCopy);

  seedThreeGenerations();
})();
