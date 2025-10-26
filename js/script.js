// script.js - sidebar toggle + small interactions
document.addEventListener('DOMContentLoaded', function(){
  // sidebar toggle
  const menuToggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  menuToggle && menuToggle.addEventListener('click', function(){
    if (!sidebar) return;
    sidebar.style.width = sidebar.style.width === '80px' ? '260px' : '80px';
    document.getElementById('content').style.marginLeft = sidebar.style.width === '80px' ? '100px' : '280px';
  });

  // small search form behaviour
  const searchForm = document.querySelector('.search-form');
  if (searchForm) {
    searchForm.addEventListener('submit', function(e){
      e.preventDefault();
      // minimal: you can extend search later
      alert('Search not implemented in demo.');
    });
  }

  // small animation on load for cards
  requestAnimationFrame(()=> {
    document.querySelectorAll('.box-info li').forEach((el,i) => {
      el.style.transform = 'translateY(8px)';
      el.style.opacity = 0;
      setTimeout(()=> {
        el.style.transition = 'all 420ms cubic-bezier(.2,.9,.3,1)';
        el.style.transform = 'translateY(0)';
        el.style.opacity = 1;
      }, i*80);
    });
  });
});
