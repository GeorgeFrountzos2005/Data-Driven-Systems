// js/dashboard.js

document.addEventListener('DOMContentLoaded', async () => {
    try {
      // Fetch users and vaccination records in parallel
      const [usersRes, recordsRes] = await Promise.all([
        fetch(`${window.location.origin}/prs/api.php/users`),
        fetch(`${window.location.origin}/prs/api.php/vaccination_records`)
      ]);
  
      const users = await usersRes.json();
      const records = await recordsRes.json();
  
      // Build a map of user_id to full_name
      const userMap = {};
      users.forEach(u => { userMap[u.user_id] = u.full_name; });
  
      // 1) Bar chart: vaccinations per user
      const countsByUser = {};
      records.forEach(r => {
        countsByUser[r.user_id] = (countsByUser[r.user_id] || 0) + 1;
      });
      const barLabels = Object.keys(countsByUser).map(id => userMap[id] || `User ${id}`);
      const barData   = Object.values(countsByUser);
      new Chart(document.getElementById('barChart'), {
        type: 'bar',
        data: {
          labels: barLabels,
          datasets: [{
            label: 'Number of Doses',
            data: barData,
          }]
        }
      });
  
      // 2) Pie chart: distribution by vaccine type
      const countsByVaccine = {};
      records.forEach(r => {
        const key = r.vaccine_name || r.vaccine_type;
        countsByVaccine[key] = (countsByVaccine[key] || 0) + 1;
      });
      const pieLabels = Object.keys(countsByVaccine);
      const pieData   = Object.values(countsByVaccine);
      new Chart(document.getElementById('pieChart'), {
        type: 'pie',
        data: {
          labels: pieLabels,
          datasets: [{ data: pieData }]
        }
      });
  
      // 3) Line chart: vaccination trends over time
      const countsByDate = {};
      records.forEach(r => {
        const date = (r.date_administered || r.vaccination_date).slice(0, 10);
        countsByDate[date] = (countsByDate[date] || 0) + 1;
      });
      const sortedDates = Object.keys(countsByDate).sort();
      const lineData    = sortedDates.map(d => countsByDate[d]);
      new Chart(document.getElementById('lineChart'), {
        type: 'line',
        data: {
          labels: sortedDates,
          datasets: [{
            label: 'Doses Administered',
            data: lineData,
            fill: false,
          }]
        },
        options: {
          scales: {
            x: { display: true, title: { display: true, text: 'Date' } },
            y: { display: true, title: { display: true, text: 'Doses' } }
          }
        }
      });
  
    } catch (err) {
      console.error('Error initializing dashboard:', err);
    }
  });
  