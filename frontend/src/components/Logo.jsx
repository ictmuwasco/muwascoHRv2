import React from 'react'

const Logo = ({ className = 'h-20 w-20' }) => {
  return (
    <img
      src="/assets/muwascologo.png"
      alt="MUWASCO Logo"
      className={`${className} object-contain rounded`}
    />
  )
}

export default Logo