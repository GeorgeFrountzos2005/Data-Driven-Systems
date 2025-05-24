// js/import.js

document.getElementById('fhirForm').addEventListener('submit', async e => {
  e.preventDefault();

  const fileInput = document.getElementById('fhirInput');
  const statusDiv = document.getElementById('status');

  if (!fileInput.files.length) {
    statusDiv.innerHTML = '<div class="alert alert-warning">Please select a JSON file.</div>';
    return;
  }

  try {
    // 1) Read the file as text
    const text = await fileInput.files[0].text();

    // 2) Send it to your FHIR‐import route
    const res  = await fetch(
        window.location.origin + '/prs/api.php/vaccination_records/fhir',
        {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    text
        }
    );

    // 3) Parse JSON response
    const textResp = await res.text();
    console.log("🔍 FHIR import raw response:", textResp);
    let data;
    try {
        data = JSON.parse(textResp);
    } catch(e) {
        console.error("❌ Failed to parse JSON:", e);
        return; // bail so you can inspect textResp
    }


    // 4) Show success or error
    if (typeof data.imported === 'number') {
      statusDiv.innerHTML = `<div class="alert alert-success">
        Imported ${data.imported} record${data.imported !== 1 ? 's' : ''} successfully.
      </div>`;
    } else {
      statusDiv.innerHTML = `<div class="alert alert-danger">
        Error: ${data.error || JSON.stringify(data)}
      </div>`;
    }
  } catch (err) {
    console.error('Import error:', err);
    statusDiv.innerHTML = `<div class="alert alert-danger">
      Unexpected error. Check the console.
    </div>`;
  }
});
