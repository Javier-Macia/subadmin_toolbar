(function () {
  try {
    var state = window.localStorage.getItem('subadminToolbar.sidebar');
    if (state === 'collapsed' || state === 'expanded') {
      document.documentElement.setAttribute('data-subadmin-sidebar', state);
    }
  }
  catch (e) {}
})();
